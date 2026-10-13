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
 * SdkLogger: Support class that gives the MCP SDK a PSR-3 logger on top of the log service.
 *
 * Failures that the SDK answers itself, such as a crashed tool, are expected to reach the app
 * only through the SDK's logger, so this class is where they become visible. It filters by a
 * minimum level and reduces context values to data that the log service can write.
 *
 * Rules:
 * - Write through the log service only, with the category mcp.sdk.
 * - Never write an object as it is; reduce it to data first.
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

	/** Nesting depth kept in context arrays and exception chains. */
	private const int MAX_DEPTH = 4;

	/** Minimum rank written, or null to write nothing. */
	private readonly ?int $minRank;


	/**
	 * Create the logger for one log file and minimum level.
	 *
	 * @param App $app App with the log service.
	 * @param string $file Log file name for log->write(), such as "mcp".
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
	 * Behavior:
	 * - Writes nothing below the minimum level, and nothing at all without one.
	 * - Puts the level into the context, next to the reduced context values.
	 *
	 * @param mixed $level PSR-3 level.
	 * @param string|\Stringable $message Message text.
	 * @param array<array-key, mixed> $context Context data.
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
	 * Reduce context values to data that the log service can write.
	 *
	 * Behavior:
	 * - A \Throwable becomes its class, message, code, file, line, trace and previous exceptions.
	 * - A \Stringable becomes its string, any other object its class name, a resource "[resource]".
	 * - Arrays are kept down to MAX_DEPTH levels; deeper ones become "[array]".
	 * - Scalars and null stay as they are.
	 *
	 * @param array<array-key, mixed> $values Values to reduce.
	 * @param int $depth Current nesting depth.
	 * @return array<array-key, mixed> The reduced values, with the same keys.
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
	 * @return array<string, mixed> Class, message, code, file, line, trace and previous, which is null beyond MAX_DEPTH.
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
