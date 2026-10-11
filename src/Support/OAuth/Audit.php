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

/** Audit: Record OAuth lifecycle outcomes using only server-controlled fields. */
final class Audit {
	/**
	 * Append one event; never accept request bodies, tokens or exception messages.
	 *
	 * @param App $app Application providing the log service and configuration.
	 * @param string $event Server-selected lifecycle event.
	 * @param string $outcome Server-selected outcome or OAuth error type.
	 * @param string $ip Client address resolved by the trusted request service.
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
