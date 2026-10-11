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
 * Small single-host OAuth ledger with atomic replacement and a stable lock file.
 *
 * Behavior:
 * - A transaction serializes code redemption, refresh rotation and consent.
 * - Failed callbacks leave the previous ledger intact.
 * - Unknown, deleted or expired records fail closed.
 * - No bearer token, password, private key or client secret is stored here.
 *
 * Notes:
 * - Intended for one owner on one PHP host, not a distributed token store.
 * - Files must live outside the webroot and survive deployments.
 */
final class OAuthStateRepository extends BaseRepository {
	private string $dir;
	private ?array $records = null;
	private bool $dirty = false;
	private bool $admissionOnly = true;

	/**
	 * Initialize request-local state from application configuration.
	 *
	 * @return void
	 */
	protected function init(): void {
		$this->dir = \rtrim((string)$this->app->cfg->mcp->oauth->state_dir, '/\\');
		if ($this->dir === '') {
			throw new \RuntimeException('Empty OAuth state directory.');
		}
	}

	/** Execute a complete transition under one exclusive lock. */
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

	/** Read within the transaction or under a separate shared lock. */
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

	/** Writes require an explicit transaction owned by the caller. */
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
	 * Remove one record inside the caller-owned transaction.
	 *
	 * @param string $key State record key.
	 * @return void
	 */
	public function delete(string $key): void {
		$this->assertTransaction();
		unset($this->records[$key]);
		$this->dirty = true;
		if (!\str_starts_with($key, 'approval:') && !\str_starts_with($key, 'limit:')) {
			$this->admissionOnly = false;
		}
	}

	/** Revoke every extant token in a grant family after code/refresh replay. */
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

	/** Fixed-window rate counter; call inside a transaction. */
	public function countAttempt(string $key, int $window, int $limit): bool {
		$record = $this->get('limit:' . $key) ?? ['expires' => \time() + $window, 'count' => 0];
		++$record['count'];
		$this->put('limit:' . $key, $record);
		return $record['count'] <= $limit;
	}

	/**
	 * Require an active transaction before mutating state.
	 *
	 * @return void
	 */
	private function assertTransaction(): void {
		if ($this->records === null) {
			throw new \LogicException('OAuth state mutation requires a transaction.');
		}
	}

	/** @return resource A locked handle, never an unlinked lock inode. */
	private function lock(int $mode) {
		if (!\is_dir($this->dir) && !@\mkdir($this->dir, 0700, true) && !\is_dir($this->dir)) {
			throw new \RuntimeException('Cannot create OAuth state directory.');
		}

		$real = \realpath($this->dir);
		$public = \defined('CITOMNI_PUBLIC_PATH') ? \realpath(CITOMNI_PUBLIC_PATH) : false;
		if ($real === false || ($public !== false && ($real === $public || \str_starts_with($real, $public . DIRECTORY_SEPARATOR)))) {
			throw new \RuntimeException('OAuth state must be outside the public directory.');
		}

		$handle = \fopen($this->dir . '/oauth.lock', 'c+b');
		if ($handle === false || !\flock($handle, $mode)) {
			throw new \RuntimeException('Cannot lock OAuth state.');
		}

		return $handle;
	}

	/**
	 * Read and validate the bounded ledger while its stable lock is held.
	 *
	 * @return array Validated result.
	 */
	private function load(): array {
		$file = $this->dir . '/ledger.json';
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
	 * Atomically replace the ledger while preserving admission capacity for grants.
	 *
	 * @return void
	 */
	private function save(): void {
		$json = \json_encode(['version' => 1, 'records' => $this->records], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		if (\strlen($json) > ($this->admissionOnly ? 2_097_152 : 4_194_304)) {
			throw new OAuthCapacityException('OAuth ledger exceeds its size limit.');
		}

		$tmp = $this->dir . '/ledger.' . \bin2hex(\random_bytes(12)) . '.tmp';
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
			if (!\rename($tmp, $this->dir . '/ledger.json')) {
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
