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

namespace CitOmni\Mcp\Support\OAuth;

use CitOmni\Kernel\App;

/**
 * Audit: Support class that writes OAuth audit events to the MCP log.
 *
 * Gives every OAuth step one audit line shape, built from server-controlled fields only, so
 * that no request value can reach the audit log.
 *
 * Rules:
 * - Accept only server-selected values; never request bodies, tokens, codes, passwords or
 *   exception messages.
 */
final class Audit {

	/**
	 * Write one audit line with the category mcp.oauth.
	 *
	 * @param App $app App whose log service and cfg mcp are used.
	 * @param string $event Server-selected lifecycle event.
	 * @param string $outcome Server-selected outcome or OAuth error type.
	 * @param string $ip Client address resolved by the Request service.
	 * @return void
	 */
	public static function record(App $app, string $event, string $outcome, string $ip): void {
		$app->log->write((string)$app->cfg->mcp->log->file, 'mcp.oauth', $event, [
			'outcome' => $outcome,
			'owner'   => (string)$app->cfg->mcp->oauth->owner_id,
			'client'  => (string)$app->cfg->mcp->oauth->client_id,
			'ip'      => $ip,
		]);
	}


}
