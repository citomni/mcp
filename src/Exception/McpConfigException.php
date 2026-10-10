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

namespace CitOmni\Mcp\Exception;

/**
 * Thrown when mcp configuration, a tool class, or the client tokens are invalid.
 *
 * Behavior:
 * - Signals a problem the developer or operator must fix:
 *   1) An mcp.tools entry that is not a tool class, or a tool class whose
 *      DEFINITION the SDK rejects (the SDK exception is previous)
 *   2) mcp.auth.secret_keys naming no key that exists in the secrets store,
 *      or naming one whose value is empty
 *   3) An unknown mcp.log.sdk_level
 *
 * Notes:
 * - Messages name the cfg key or the tool, so the faulty definition can be found.
 * - Never caused by client input. The controller lets it bubble to the global
 *   error handler.
 */
final class McpConfigException extends \RuntimeException {}
