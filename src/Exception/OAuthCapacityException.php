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
 * OAuthCapacityException: Exception for a full OAuth ledger.
 *
 * Signals a temporary admission failure: the ledger refuses a new record rather than evict a
 * live grant. It is meant to be answered with a retryable status, not left to the global error
 * handler.
 */
final class OAuthCapacityException extends \RuntimeException {
}
