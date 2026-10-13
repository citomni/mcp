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
 * McpConfigException: Exception for invalid mcp cfg, tool classes, OAuth secrets or a missing OAuth library.
 *
 * Signals a problem that the developer or operator must fix and that client input never
 * causes, so it is left to the global error handler.
 *
 * Rules:
 * - Identify the cfg key, tool, secret or package to fix in the message, never a secret value.
 * - When it wraps an SDK or library exception, keep that exception as the previous one.
 */
final class McpConfigException extends \RuntimeException {}
