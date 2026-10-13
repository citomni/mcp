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

namespace CitOmni\Mcp\Repository;

use CitOmni\Kernel\Repository\BaseRepository;
use CitOmni\Mcp\Exception\OAuthCapacityException;

/**
 * OAuthStateRepository: Repository for the OAuth ledger, a JSON file next to a stable lock file.
 *
 * Holds the approval, rate-limit and grant records of one owner on one PHP host. It serializes
 * state transitions between PHP workers with an exclusive file lock and replaces the ledger
 * atomically. What a record means belongs to its callers. The ledger is not a distributed
 * token store.
 *
 * Rules:
 * - Change records only inside transaction(); a failed transition leaves the previous ledger.
 * - Treat a missing or expired record as absent.
 * - Never evict a live record to make room; refuse a new one with OAuthCapacityException.
 * - Keep the files outside the public directory, and never delete the lock file.
 */
final class OAuthStateRepository extends BaseRepository {

	/** Directory of ledger.json and oauth.lock. */
	private string $dir;

	/** @var array<string, array<string, mixed>>|null Records of the open transaction by key; null outside a transaction. */
	private ?array $records = null;

	/** Whether the open transaction changed or dropped a record. */
	private bool $dirty = false;

	/** Whether the open transaction changed only approval and rate-limit records, which have a lower size limit. */
	private bool $admissionOnly = true;


	/**
	 * Read the ledger directory from cfg.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Assumes that Config validated state_dir as a non-empty string. The directory checks use
		// it as configured: trimming its separators would turn / into '' and C:\ into C:, which
		// they would not read as a root.
		$this->dir = (string)$this->app->cfg->mcp->oauth->state_dir;
	}


	/**
	 * Run one state transition under the exclusive ledger lock.
	 *
	 * Serializes every state change between the PHP workers of the host, so that a transition
	 * sees the records that the previous one saved.
	 *
	 * Behavior:
	 * - Loads the ledger and drops expired records first.
	 * - Saves the ledger when anything changed, the dropped records included. An exception from
	 *   the action leaves the previous ledger in place.
	 *
	 * @param callable(): mixed $action Transition that reads and writes through this repository.
	 * @return mixed The action's result.
	 * @throws \LogicException When a transaction is already open.
	 * @throws \RuntimeException When the ledger cannot be locked, read or written, or its directory lies inside the public directory.
	 * @throws \JsonException When the ledger is not valid JSON.
	 * @throws OAuthCapacityException When the saved ledger would exceed its size limit.
	 */
	public function transaction(callable $action): mixed {
		if ($this->records !== null) {
			throw new \LogicException('Nested OAuth state transactions are not supported.');
		}

		$lock = $this->lock(LOCK_EX);
		try {
			$this->records = $this->load();
			$this->dirty = false;
			$this->admissionOnly = true;
			$now = \time();
			foreach ($this->records as $key => $record) {
				if (($record['expires'] ?? 0) <= $now) {
					unset($this->records[$key]);
					$this->dirty = true;
				}
			}
			$result = $action();
			if ($this->dirty) {
				$this->save();
			}
			return $result;
		} finally {
			$this->records = null;
			\flock($lock, LOCK_UN);
			\fclose($lock);
		}
	}


	/**
	 * Read one record, inside the open transaction or under a shared lock.
	 *
	 * @param string $key Record key, such as "family:<id>".
	 * @return array<string, mixed>|null The record, or null when it is missing or expired.
	 * @throws \RuntimeException When the ledger cannot be locked or read outside a transaction, or its directory lies inside the public directory.
	 * @throws \JsonException When the ledger read outside a transaction is not valid JSON.
	 */
	public function get(string $key): ?array {
		if ($this->records !== null) {
			$record = $this->records[$key] ?? null;
		} else {
			$lock = $this->lock(LOCK_SH);
			try {
				$record = $this->load()[$key] ?? null;
			} finally {
				\flock($lock, LOCK_UN);
				\fclose($lock);
			}
		}

		return \is_array($record) && ($record['expires'] ?? 0) > \time() ? $record : null;
	}


	/**
	 * Insert or replace one record inside the caller's transaction.
	 *
	 * The admission limits keep records that anyone can create from crowding out grants. The
	 * transaction saves the change.
	 *
	 * Behavior:
	 * - A new approval or rate-limit record is admitted only while the ledger holds fewer than
	 *   2,048 records, which keeps room for grant records. Any new record is admitted only while
	 *   it holds fewer than 4,096.
	 * - Replacing an existing record is always admitted.
	 *
	 * @param string $key Record key.
	 * @param array<string, mixed> $record Record with an integer expires.
	 * @return void
	 * @throws \LogicException When no transaction is open.
	 * @throws OAuthCapacityException When the ledger is full for this kind of record.
	 */
	public function put(string $key, array $record): void {
		$this->assertTransaction();
		// Public approval/rate records cannot consume the capacity reserved for grants.
		$limit = \str_starts_with($key, 'approval:') || \str_starts_with($key, 'limit:') ? 2048 : 4096;
		if (!isset($this->records[$key]) && \count($this->records) >= $limit) {
			throw new OAuthCapacityException('OAuth state capacity reached; retry after short-lived records expire.');
		}

		$this->records[$key] = $record;
		$this->dirty = true;
		if (!\str_starts_with($key, 'approval:') && !\str_starts_with($key, 'limit:')) {
			$this->admissionOnly = false;
		}
	}


	/**
	 * Remove one record inside the caller's transaction.
	 *
	 * The transaction saves the change; removing a missing record is no error.
	 *
	 * @param string $key Record key.
	 * @return void
	 * @throws \LogicException When no transaction is open.
	 */
	public function delete(string $key): void {
		$this->assertTransaction();
		unset($this->records[$key]);
		$this->dirty = true;
		if (!\str_starts_with($key, 'approval:') && !\str_starts_with($key, 'limit:')) {
			$this->admissionOnly = false;
		}
	}


	/**
	 * Mark every record of a grant family revoked.
	 *
	 * Revokes the code, the access tokens and the refresh state of the family at once, so that
	 * a replay ends the whole grant. The records keep their expiry.
	 *
	 * @param string $family Grant family: the identifier of its authorization code.
	 * @return void
	 * @throws \LogicException When no transaction is open.
	 */
	public function revokeFamily(string $family): void {
		$this->assertTransaction();
		$this->admissionOnly = false;
		foreach ($this->records as $key => $record) {
			if (($record['family'] ?? null) === $family) {
				$this->records[$key]['revoked'] = true;
				$this->dirty = true;
			}
		}
	}


	/**
	 * Count one attempt in a fixed window, inside the caller's transaction.
	 *
	 * Keeps one counter per key; its window starts with the first attempt and ends with the
	 * counter's expiry.
	 *
	 * @param string $key Counter key, stored as "limit:<key>".
	 * @param int $window Window length in seconds, from the first attempt.
	 * @param int $limit Attempts allowed in the window.
	 * @return bool True when this attempt is within the limit. The attempt counts either way.
	 * @throws \LogicException When no transaction is open.
	 * @throws OAuthCapacityException When the ledger is full for rate-limit records.
	 */
	public function countAttempt(string $key, int $window, int $limit): bool {
		$record = $this->get('limit:' . $key) ?? ['expires' => \time() + $window, 'count' => 0];
		++$record['count'];
		$this->put('limit:' . $key, $record);
		return $record['count'] <= $limit;
	}


	/**
	 * Require an open transaction before a record changes.
	 *
	 * @return void
	 * @throws \LogicException When no transaction is open.
	 */
	private function assertTransaction(): void {
		if ($this->records === null) {
			throw new \LogicException('OAuth state mutation requires a transaction.');
		}
	}


	/**
	 * Build the path of a file in the ledger directory.
	 *
	 * @param string $name File name.
	 * @return string The directory without trailing separators, a slash and the name, so that a root such as / or C:\ stays a root.
	 */
	private function path(string $name): string {
		return \rtrim($this->dir, '/\\') . '/' . $name;
	}


	/**
	 * Open and lock the stable lock file, creating the ledger directory if needed.
	 *
	 * @param int $mode LOCK_SH or LOCK_EX.
	 * @return resource The locked handle; the lock file is never unlinked.
	 * @throws \RuntimeException When the directory cannot be created, lies inside the public directory, or cannot be locked.
	 */
	private function lock(int $mode) {
		if (!\is_dir($this->dir) && !@\mkdir($this->dir, 0700, true) && !\is_dir($this->dir)) {
			throw new \RuntimeException('Cannot create OAuth state directory.');
		}

		$real = \realpath($this->dir);
		$public = \defined('CITOMNI_PUBLIC_PATH') ? \realpath(CITOMNI_PUBLIC_PATH) : false;
		if ($real === false || ($public !== false && ($real === $public || \str_starts_with($real, $public . DIRECTORY_SEPARATOR)))) {
			throw new \RuntimeException('OAuth state must be outside the public directory.');
		}

		$handle = \fopen($this->path('oauth.lock'), 'c+b');
		if ($handle === false || !\flock($handle, $mode)) {
			throw new \RuntimeException('Cannot lock OAuth state.');
		}

		return $handle;
	}


	/**
	 * Read and validate the ledger while its lock is held.
	 *
	 * @return array<string, array<string, mixed>> Records by key; empty when no ledger exists.
	 * @throws \RuntimeException When the ledger is unreadable, larger than 4 MiB or invalid.
	 * @throws \JsonException When the ledger is not valid JSON.
	 */
	private function load(): array {
		$file = $this->path('ledger.json');
		if (!\is_file($file)) {
			return [];
		}

		$raw = \file_get_contents($file);
		if ($raw === false || \strlen($raw) > 4_194_304) {
			throw new \RuntimeException('Cannot read OAuth ledger or ledger exceeds its size limit.');
		}

		$data = \json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
		if (!\is_array($data) || ($data['version'] ?? null) !== 1 || !\is_array($data['records'] ?? null)) {
			throw new \RuntimeException('Invalid OAuth ledger.');
		}

		foreach ($data['records'] as $record) {
			if (!\is_array($record) || !\is_int($record['expires'] ?? null)) {
				throw new \RuntimeException('Invalid OAuth ledger record.');
			}
		}

		return $data['records'];
	}


	/**
	 * Replace the ledger atomically.
	 *
	 * Behavior:
	 * - A ledger of more than 4 MiB is refused, and one of more than 2 MiB when the transaction
	 *   changed only approval and rate-limit records, which keeps room for grant records.
	 * - Writes a temporary file with mode 0600 and renames it over ledger.json.
	 *
	 * @return void
	 * @throws OAuthCapacityException When the ledger would exceed its size limit.
	 * @throws \RuntimeException When the ledger cannot be written or replaced.
	 */
	private function save(): void {
		$json = \json_encode(['version' => 1, 'records' => $this->records], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		if (\strlen($json) > ($this->admissionOnly ? 2_097_152 : 4_194_304)) {
			throw new OAuthCapacityException('OAuth ledger exceeds its size limit.');
		}

		$tmp = $this->path('ledger.' . \bin2hex(\random_bytes(12)) . '.tmp');
		$handle = \fopen($tmp, 'x+b');
		if ($handle === false) {
			throw new \RuntimeException('Cannot create OAuth ledger temporary file.');
		}
		try {
			\chmod($tmp, 0600);
			if (\fwrite($handle, $json) !== \strlen($json) || !\fflush($handle)) {
				throw new \RuntimeException('Cannot write OAuth ledger.');
			}
			\fclose($handle);
			$handle = null;
			if (!\rename($tmp, $this->path('ledger.json'))) {
				throw new \RuntimeException('Cannot replace OAuth ledger.');
			}
		} finally {
			if (\is_resource($handle)) {
				\fclose($handle);
			}
			if (\is_file($tmp)) {
				\unlink($tmp);
			}
		}
	}


}
