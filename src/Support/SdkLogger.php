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

namespace CitOmni\Mcp\Support;

use CitOmni\Kernel\App;
use CitOmni\Mcp\Exception\McpConfigException;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;

/**
 * SdkLogger: PSR-3 logger that writes the MCP SDK's messages to the log service.
 *
 * Behavior:
 * - Writes messages at or above the minimum level to $app->log, category
 *   "mcp.sdk", with the level in the context. A null minimum level discards
 *   every message.
 * - Reduces context values to JSON-friendly data:
 *   1) A \Throwable becomes class, message, code, file, line, trace, and its
 *      previous exceptions
 *   2) A \Stringable becomes its string, any other object its class name
 *   3) Arrays are kept down to four levels; scalars and null as they are
 *
 * Notes:
 * - The SDK answers the client itself when a tool throws something other than
 *   ToolCallException (JSON-RPC -32603) and logs the exception at error level.
 *   This logger is the only place such failures show up.
 * - At info the SDK logs every raw message of a handshake-era client, tool
 *   arguments included; at debug also the arguments of 2026-07-28 tool calls.
 *   The bearer token is not part of them; Psr7Bridge strips Authorization.
 * - With zend.exception_ignore_args off, exception traces carry string
 *   arguments, cut to 15 bytes by PHP.
 *
 * Typical usage:
 *   $logger = new SdkLogger($this->app, 'mcp', 'error');
 *   Server::builder()->setLogger($logger);
 */
final class SdkLogger extends AbstractLogger {

	/** PSR-3 levels by severity, lowest first. */
	private const array RANKS = [
		'debug'     => 0,
		'info'      => 1,
		'notice'    => 2,
		'warning'   => 3,
		'error'     => 4,
		'critical'  => 5,
		'alert'     => 6,
		'emergency' => 7,
	];

	/** Nesting depth kept in context arrays. */
	private const int MAX_DEPTH = 4;

	/** Minimum rank written, or null to write nothing. */
	private readonly ?int $minRank;


	/**
	 * @param App $app Application instance (log service).
	 * @param string $file Log file name for log->write(), e.g. "mcp".
	 * @param string|null $minLevel Lowest PSR-3 level written, or null for none.
	 * @throws McpConfigException When $minLevel is not a PSR-3 level.
	 */
	public function __construct(private readonly App $app, private readonly string $file, ?string $minLevel) {
		if ($minLevel !== null && !isset(self::RANKS[$minLevel])) {
			throw new McpConfigException(
				"mcp.log.sdk_level must be a PSR-3 level (debug ... emergency) or null, got '{$minLevel}'."
			);
		}

		$this->minRank = $minLevel === null ? null : self::RANKS[$minLevel];
	}


	/**
	 * Write one SDK message when its level reaches the minimum.
	 *
	 * @param mixed $level PSR-3 level.
	 * @param string|\Stringable $message Message text.
	 * @param array<array-key, mixed> $context Context data, reduced as described in the class doc.
	 * @return void
	 * @throws InvalidArgumentException When $level is not a PSR-3 level.
	 */
	public function log($level, string|\Stringable $message, array $context = []): void {
		$rank = \is_string($level) ? (self::RANKS[$level] ?? null) : null;

		if ($rank === null) {
			throw new InvalidArgumentException('Unknown PSR-3 log level: ' . \get_debug_type($level));
		}

		if ($this->minRank === null || $rank < $this->minRank) {
			return;
		}

		$this->app->log->write($this->file, 'mcp.sdk', (string)$message, ['level' => $level] + self::reduce($context, 0));
	}


	/**
	 * Reduce context values to JSON-friendly data.
	 *
	 * @param array<array-key, mixed> $values Values to reduce.
	 * @param int $depth Current nesting depth.
	 * @return array<array-key, mixed>
	 */
	private static function reduce(array $values, int $depth): array {
		foreach ($values as $key => $value) {
			$values[$key] = match (true) {
				$value instanceof \Throwable => self::throwable($value, $depth),
				$value instanceof \Stringable => (string)$value,
				\is_object($value) => \get_debug_type($value),
				\is_array($value) => $depth < self::MAX_DEPTH ? self::reduce($value, $depth + 1) : '[array]',
				\is_resource($value) => '[resource]',
				default => $value,
			};
		}

		return $values;
	}


	/**
	 * Describe an exception and its previous chain.
	 *
	 * @param \Throwable $e Exception to describe.
	 * @param int $depth Current nesting depth.
	 * @return array<string, mixed>
	 */
	private static function throwable(\Throwable $e, int $depth): array {
		$previous = $e->getPrevious();

		return [
			'class'    => $e::class,
			'message'  => $e->getMessage(),
			'code'     => $e->getCode(),
			'file'     => $e->getFile(),
			'line'     => $e->getLine(),
			'trace'    => $e->getTraceAsString(),
			'previous' => $previous !== null && $depth < self::MAX_DEPTH ? self::throwable($previous, $depth + 1) : null,
		];
	}

}
