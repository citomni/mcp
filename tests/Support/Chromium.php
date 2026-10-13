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

namespace CitOmni\Mcp\Tests\Support;

/**
 * Chromium: Headless Chrome, Chromium or Edge, driven over the DevTools protocol, for browser tests.
 *
 * Lets a suite load pages, type, click and read browser state in a real browser engine, without
 * Node or a browser automation package. It speaks the DevTools protocol over the browser's
 * WebSocket, one flattened session per page, and leaves what to check to the suite.
 *
 * Rules:
 * - Find the browser in standard install locations and on PATH only; no setting selects it.
 * - Run it headless with a throwaway profile, and ignore certificate errors only for the key the
 *   caller names.
 * - Leave no browser running when the suite ends.
 * - Wait for nothing without a deadline. Once the browser runs, a protocol error, a script error
 *   or a timeout throws.
 *
 * Typical usage:
 *   $browser = Chromium::start($dir, $spkiHash) ?? fail(Chromium::$lastFailure);
 *   $page = $browser->newPage();
 *   $browser->navigate($page, $url);
 *   $title = $browser->evaluate($page, 'document.title');
 *   $browser->closePage($page);
 *   $browser->close();
 */
final class Chromium {

	/** Why the last start() returned null. */
	public static string $lastFailure = '';

	/** Seconds to wait for a command result or an event. */
	private const float TIMEOUT = 15.0;

	/** GUID that RFC 6455 appends to the WebSocket key to form the accept value. */
	private const string WEBSOCKET_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

	/** @var resource|null Browser process; null once closed. */
	private $process;

	/** @var resource|null DevTools WebSocket; null once closed. */
	private $socket;

	/** Received bytes not yet consumed as WebSocket frames. */
	private string $buffer = '';

	/** Id of the last command sent. */
	private int $lastId = 0;

	/** @var list<array{method: string, params: array<string, mixed>, sessionId: string|null}> Events received while waiting for something else, oldest first. */
	private array $events = [];

	/** @var array<string, array{context: string, frame: string, loader: string}> Open pages by session id: browser context, main frame, and loader of the current document. */
	private array $pages = [];


	/**
	 * Wrap a started browser process.
	 *
	 * @param resource $process Browser process.
	 */
	private function __construct($process) {
		$this->process = $process;
	}


	// ----------------------------------------------------------------
	// Lifecycle
	// ----------------------------------------------------------------

	/**
	 * Start the browser and connect to it.
	 *
	 * Behavior:
	 * - Uses the first browser that find() returns.
	 * - Runs it headless with the profile in $dir/profile and its output in $dir/browser.log.
	 * - Returns null when no browser is found or it does not start; $lastFailure then says why,
	 *   with the locations searched.
	 * - Registers close() as a shutdown function.
	 *
	 * @param string $dir Existing empty directory for the profile and the log.
	 * @param string $spkiHash Base64 SHA-256 of the SubjectPublicKeyInfo whose certificate errors are ignored.
	 * @return self|null The connected browser, or null when it cannot be started.
	 * @see self::find()  Where and in which order it looks for a browser.
	 */
	public static function start(string $dir, string $spkiHash): ?self {
		self::$lastFailure = '';
		$executable = self::find($searched);

		if ($executable === null) {
			self::$lastFailure = 'No Chrome, Chromium or Edge found. Searched: ' . \implode(', ', $searched);

			return null;
		}

		$profile = $dir . '/profile';
		$log = $dir . '/browser.log';
		$command = [
			$executable,
			'--headless=new',
			'--remote-debugging-port=0',
			'--user-data-dir=' . $profile,
			'--ignore-certificate-errors-spki-list=' . $spkiHash,
			'--no-first-run',
			'--no-default-browser-check',
			'--no-proxy-server',
			'--disable-background-networking',
			'--disable-component-update',
			'--disable-dev-shm-usage',
			'--disable-extensions',
			'--disable-gpu',
			'--disable-sync',
			'--password-store=basic',
			'--use-mock-keychain',
		];

		// Chrome refuses to run as root with its sandbox.
		if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
			$command[] = '--no-sandbox';
		}

		$command[] = 'about:blank';
		$process = @\proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);

		if (!\is_resource($process)) {
			self::$lastFailure = 'proc_open() failed for ' . $executable;

			return null;
		}

		\fclose($pipes[0]);
		$browser = new self($process);
		\register_shutdown_function([$browser, 'close']);

		try {
			[$port, $path] = $browser->awaitDevTools($log, $profile . '/DevToolsActivePort');
			$browser->connect($port, $path);
			// A first command proves that the protocol works before any page is opened.
			$browser->send('Browser.getVersion');
		} catch (\RuntimeException $e) {
			$browser->close();
			self::$lastFailure = $executable . ': ' . $e->getMessage() . "\nBrowser log:\n" . \substr((string)@\file_get_contents($log), -2000);

			return null;
		}

		return $browser;
	}


	/**
	 * Close the browser. Safe to call more than once.
	 *
	 * Behavior:
	 * - Asks the browser to close, waits up to 5 seconds for it to exit, and then terminates it.
	 *
	 * @return void
	 */
	public function close(): void {
		if ($this->socket !== null) {
			try {
				$this->send('Browser.close', [], null, 2.0);
			} catch (\RuntimeException) {
				// The browser may drop the connection before it answers; it is closing either way.
			}

			@\fclose($this->socket);
			$this->socket = null;
		}

		if ($this->process === null) {
			return;
		}

		$deadline = \hrtime(true) + 5_000_000_000;

		while (\proc_get_status($this->process)['running'] && \hrtime(true) < $deadline) {
			\usleep(50_000);
		}

		@\proc_terminate($this->process);
		@\proc_close($this->process);
		$this->process = null;
	}


	// ----------------------------------------------------------------
	// Pages
	// ----------------------------------------------------------------

	/**
	 * Open a blank page in a browser context of its own, and attach to it.
	 *
	 * Behavior:
	 * - The page shares no cookies, storage or cache with other pages.
	 *
	 * @return string Session id of the page, for the page methods.
	 * @throws \RuntimeException When the browser refuses a command or does not answer in time.
	 */
	public function newPage(): string {
		$context = $this->send('Target.createBrowserContext')['browserContextId'];
		$targetId = $this->send('Target.createTarget', ['url' => 'about:blank', 'browserContextId' => $context])['targetId'];
		$session = $this->send('Target.attachToTarget', ['targetId' => $targetId, 'flatten' => true])['sessionId'];
		$this->send('Page.enable', [], $session);
		$this->send('Page.setLifecycleEventsEnabled', ['enabled' => true], $session);
		$this->send('Log.enable', [], $session);
		$frame = $this->send('Page.getFrameTree', [], $session)['frameTree']['frame'];
		$this->pages[$session] = ['context' => $context, 'frame' => $frame['id'], 'loader' => $frame['loaderId']];

		return $session;
	}


	/**
	 * Close a page together with its browser context. Safe to call more than once.
	 *
	 * @param string $session Page session.
	 * @return void
	 * @throws \RuntimeException When the browser refuses the command or does not answer in time.
	 */
	public function closePage(string $session): void {
		if (!isset($this->pages[$session])) {
			return;
		}

		$context = $this->pages[$session]['context'];
		unset($this->pages[$session]);
		$this->events = \array_values(\array_filter($this->events, static fn(array $event): bool => $event['sessionId'] !== $session));
		$this->send('Target.disposeBrowserContext', ['browserContextId' => $context]);
	}


	/**
	 * Load a URL in a page and wait for the load event of that document.
	 *
	 * @param string $session Page session.
	 * @param string $url URL.
	 * @return void
	 * @throws \RuntimeException When navigation fails or the document does not load in time.
	 */
	public function navigate(string $session, string $url): void {
		$result = $this->send('Page.navigate', ['url' => $url], $session);

		if (isset($result['errorText']) || !isset($result['loaderId'])) {
			throw new \RuntimeException('Navigation to ' . $url . ' loaded no document: ' . ($result['errorText'] ?? 'same document'));
		}

		$loader = $result['loaderId'];
		$this->awaitLoad($session, static fn(string $loaded): bool => $loaded === $loader);
	}


	/**
	 * Wait until a page has loaded a document other than its current one.
	 *
	 * Serves navigations that the page starts itself, such as a form submission and the redirects
	 * that follow it.
	 *
	 * @param string $session Page session.
	 * @return void
	 * @throws \RuntimeException When no other document loads in time.
	 */
	public function awaitNewDocument(string $session): void {
		$current = $this->pages[$session]['loader'] ?? '';
		$this->awaitLoad($session, static fn(string $loaded): bool => $loaded !== $current);
	}


	/**
	 * Evaluate a JavaScript expression in a page.
	 *
	 * Behavior:
	 * - Waits for a promise to settle and returns its value.
	 * - Returns values by value, so objects arrive as arrays; undefined arrives as null.
	 *
	 * @param string $session Page session.
	 * @param string $expression JavaScript expression.
	 * @return mixed The value.
	 * @throws \RuntimeException When the script throws, or the browser does not answer in time.
	 */
	public function evaluate(string $session, string $expression): mixed {
		$result = $this->send('Runtime.evaluate', ['expression' => $expression, 'awaitPromise' => true, 'returnByValue' => true], $session);

		if (isset($result['exceptionDetails'])) {
			$details = $result['exceptionDetails'];

			throw new \RuntimeException('Script failed: ' . ($details['exception']['description'] ?? $details['text'] ?? 'unknown error'));
		}

		return $result['result']['value'] ?? null;
	}


	/**
	 * Replace the value of a form field with inserted text, as an input method commits it.
	 *
	 * @param string $session Page session.
	 * @param string $selector CSS selector of the field.
	 * @param string $text Text to type.
	 * @return void
	 * @throws \RuntimeException When no element matches, or the browser does not answer in time.
	 */
	public function type(string $session, string $selector, string $text): void {
		$this->evaluate($session, '(() => { const field = ' . self::query($selector) . '; field.focus(); field.select(); })()');
		$this->send('Input.insertText', ['text' => $text], $session);
	}


	/**
	 * Click the centre of an element with the left mouse button.
	 *
	 * Behavior:
	 * - Scrolls the element into view and dispatches real mouse events, so the click carries
	 *   user activation like a user's click.
	 *
	 * @param string $session Page session.
	 * @param string $selector CSS selector of the element.
	 * @return void
	 * @throws \RuntimeException When no element matches, or the browser does not answer in time.
	 */
	public function click(string $session, string $selector): void {
		$point = $this->evaluate($session, '(() => { const element = ' . self::query($selector) . '; element.scrollIntoView({block: "center"}); '
			. 'const box = element.getBoundingClientRect(); return {x: box.left + box.width / 2, y: box.top + box.height / 2}; })()');

		foreach (['mouseMoved', 'mousePressed', 'mouseReleased'] as $type) {
			$this->send('Input.dispatchMouseEvent', ['type' => $type, 'x' => $point['x'], 'y' => $point['y'], 'button' => 'left', 'clickCount' => 1], $session);
		}
	}


	// ----------------------------------------------------------------
	// Protocol
	// ----------------------------------------------------------------

	/**
	 * Send a DevTools command and return its result.
	 *
	 * Behavior:
	 * - Queues the events that arrive before the result, for waitFor() and takeEvents().
	 *
	 * @param string $method Command, such as Page.navigate.
	 * @param array<string, mixed> $params Command parameters.
	 * @param string|null $session Page session, or null for the browser.
	 * @param float $timeout Seconds to wait for the result.
	 * @return array<string, mixed> The result.
	 * @throws \RuntimeException When the browser returns an error, closes the connection or does not answer in time.
	 */
	public function send(string $method, array $params = [], ?string $session = null, float $timeout = self::TIMEOUT): array {
		if ($this->socket === null) {
			throw new \RuntimeException('The browser is closed.');
		}

		$id = ++$this->lastId;
		$message = ['id' => $id, 'method' => $method, 'params' => $params === [] ? new \stdClass() : $params];

		if ($session !== null) {
			$message['sessionId'] = $session;
		}

		$this->writeFrame(\json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), 1);
		$deadline = \hrtime(true) + (int)($timeout * 1e9);

		while (true) {
			$received = $this->readMessage($deadline, $method);

			if (($received['id'] ?? null) === $id) {
				if (isset($received['error'])) {
					throw new \RuntimeException($method . ' failed: ' . ($received['error']['message'] ?? 'unknown error'));
				}

				return $received['result'] ?? [];
			}

			$this->queue($received);
		}
	}


	/**
	 * Wait for an event and return its parameters.
	 *
	 * Behavior:
	 * - Takes the oldest queued match first; otherwise reads until a match arrives, queuing every
	 *   other event.
	 *
	 * @param string $method Event, such as Page.lifecycleEvent.
	 * @param string|null $session Page session, or null for the browser.
	 * @param float $timeout Seconds to wait.
	 * @param (\Closure(array<string, mixed>): bool)|null $match Tells from the parameters whether an event matches; null matches every event of the kind.
	 * @return array<string, mixed> Event parameters.
	 * @throws \RuntimeException When the event does not arrive in time or the connection closes.
	 */
	public function waitFor(string $method, ?string $session, float $timeout = self::TIMEOUT, ?\Closure $match = null): array {
		foreach ($this->events as $i => $event) {
			if ($event['method'] === $method && $event['sessionId'] === $session && ($match === null || $match($event['params']))) {
				\array_splice($this->events, $i, 1);

				return $event['params'];
			}
		}

		$deadline = \hrtime(true) + (int)($timeout * 1e9);

		while (true) {
			$received = $this->readMessage($deadline, $method);

			if (($received['method'] ?? null) === $method && ($received['sessionId'] ?? null) === $session && ($match === null || $match($received['params'] ?? []))) {
				return $received['params'] ?? [];
			}

			$this->queue($received);
		}
	}


	/**
	 * Remove and return the queued events of one kind.
	 *
	 * @param string $method Event.
	 * @param string|null $session Page session, or null for the browser.
	 * @return list<array<string, mixed>> Their parameters, oldest first.
	 */
	public function takeEvents(string $method, ?string $session): array {
		$taken = [];
		$kept = [];

		foreach ($this->events as $event) {
			if ($event['method'] === $method && $event['sessionId'] === $session) {
				$taken[] = $event['params'];
			} else {
				$kept[] = $event;
			}
		}

		$this->events = $kept;

		return $taken;
	}


	// ----------------------------------------------------------------
	// Internals
	// ----------------------------------------------------------------

	/**
	 * Find the browser executable.
	 *
	 * Behavior:
	 * - Windows: Google\Chrome\Application\chrome.exe, then Chromium\Application\chrome.exe, then
	 *   Microsoft\Edge\Application\msedge.exe, each under %ProgramFiles%, %ProgramFiles(x86)% and
	 *   %LOCALAPPDATA%; then chrome.exe and msedge.exe on PATH.
	 * - macOS: Google Chrome, Chromium and Microsoft Edge in /Applications; then google-chrome and
	 *   chromium on PATH.
	 * - Other systems: google-chrome, google-chrome-stable, chromium, chromium-browser, chrome,
	 *   microsoft-edge and microsoft-edge-stable on PATH.
	 *
	 * @param list<string>|null $searched Receives the locations and PATH names searched.
	 * @return string|null Path of the first browser found, or null.
	 */
	private static function find(?array &$searched): ?string {
		$searched = [];
		$windows = \PHP_OS_FAMILY === 'Windows';
		$paths = [];

		if ($windows) {
			// Standard install roots of Windows, where the installers put the browsers.
			$roots = \array_filter([\getenv('ProgramFiles'), \getenv('ProgramFiles(x86)'), \getenv('LOCALAPPDATA')], static fn(string|false $root): bool => \is_string($root) && $root !== '');

			foreach (['Google\\Chrome\\Application\\chrome.exe', 'Chromium\\Application\\chrome.exe', 'Microsoft\\Edge\\Application\\msedge.exe'] as $relative) {
				foreach ($roots as $root) {
					$paths[] = $root . '\\' . $relative;
				}
			}

			$names = ['chrome.exe', 'msedge.exe'];
		} elseif (\PHP_OS_FAMILY === 'Darwin') {
			$paths = [
				'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
				'/Applications/Chromium.app/Contents/MacOS/Chromium',
				'/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
			];
			$names = ['google-chrome', 'chromium'];
		} else {
			$names = ['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser', 'chrome', 'microsoft-edge', 'microsoft-edge-stable'];
		}

		foreach ($paths as $path) {
			$searched[] = $path;

			if (\is_file($path)) {
				return $path;
			}
		}

		$dirs = \array_filter(\explode(\PATH_SEPARATOR, (string)\getenv('PATH')), static fn(string $dir): bool => $dir !== '');

		foreach ($names as $name) {
			$searched[] = $name . ' on PATH';

			foreach ($dirs as $dir) {
				$path = \rtrim($dir, '/\\') . \DIRECTORY_SEPARATOR . $name;

				if (\is_file($path) && ($windows || \is_executable($path))) {
					return $path;
				}
			}
		}

		return null;
	}


	/**
	 * Wait up to 30 seconds for the browser to report its DevTools endpoint.
	 *
	 * Behavior:
	 * - Reads the DevTools listening line of the browser's output, or else the DevToolsActivePort
	 *   file in the profile. A sandboxed browser, such as a snap package, may write the file where
	 *   PHP cannot see it.
	 *
	 * @param string $log File that receives the browser's output.
	 * @param string $file The DevToolsActivePort file in the profile.
	 * @return array{0: int, 1: string} Port on 127.0.0.1 and WebSocket path.
	 * @throws \RuntimeException When the browser exits or reports nothing in time.
	 */
	private function awaitDevTools(string $log, string $file): array {
		$deadline = \hrtime(true) + 30_000_000_000;

		while (\hrtime(true) < $deadline) {
			if (!\proc_get_status($this->process)['running']) {
				throw new \RuntimeException('The browser exited during start.');
			}

			if (\is_file($log) && \preg_match('~DevTools listening on ws://127\.0\.0\.1:(\d+)(/devtools/browser/\S+)~', (string)\file_get_contents($log), $m) === 1) {
				return [(int)$m[1], $m[2]];
			}

			$lines = \is_file($file) ? \explode("\n", (string)\file_get_contents($file)) : [];

			if (\count($lines) >= 2 && \preg_match('/^\d+$/', \trim($lines[0])) === 1 && \str_starts_with(\trim($lines[1]), '/devtools/browser/')) {
				return [(int)\trim($lines[0]), \trim($lines[1])];
			}

			\usleep(50_000);
		}

		throw new \RuntimeException('No DevTools endpoint within 30 seconds.');
	}


	/**
	 * Open the DevTools WebSocket.
	 *
	 * @param int $port DevTools port on 127.0.0.1.
	 * @param string $path WebSocket path.
	 * @return void
	 * @throws \RuntimeException When the connection or the WebSocket handshake fails.
	 */
	private function connect(int $port, string $path): void {
		$socket = @\stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 5.0);

		if ($socket === false) {
			throw new \RuntimeException('Cannot connect to DevTools on port ' . $port . ': ' . $error);
		}

		\stream_set_read_buffer($socket, 0);
		$this->socket = $socket;
		$key = \base64_encode(\random_bytes(16));

		// No Origin header: the browser accepts DevTools clients without one, and refuses
		// unlisted origins.
		$this->write("GET {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
			. "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");
		$deadline = \hrtime(true) + 5_000_000_000;

		while (($end = \strpos($this->buffer, "\r\n\r\n")) === false) {
			$this->fill($deadline, 'the WebSocket handshake');
		}

		$head = \substr($this->buffer, 0, $end);
		$this->buffer = (string)\substr($this->buffer, $end + 4);
		$accept = \base64_encode(\sha1($key . self::WEBSOCKET_GUID, true));

		if (\preg_match('~^HTTP/1\.1 101 ~', $head) !== 1 || \preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $head, $m) !== 1 || $m[1] !== $accept) {
			throw new \RuntimeException("WebSocket handshake refused:\n" . $head);
		}
	}


	/**
	 * Wait for the load event of a main-frame document, and make that document the current one.
	 *
	 * @param string $session Page session.
	 * @param \Closure(string): bool $wanted Tells from a loader id whether the document is the one awaited.
	 * @return void
	 * @throws \RuntimeException When the page is not open or the document does not load in time.
	 */
	private function awaitLoad(string $session, \Closure $wanted): void {
		$frame = $this->pages[$session]['frame'] ?? throw new \RuntimeException('No open page has session ' . $session . '.');
		$event = $this->waitFor('Page.lifecycleEvent', $session, self::TIMEOUT, static fn(array $params): bool => ($params['name'] ?? '') === 'load'
			&& ($params['frameId'] ?? '') === $frame && $wanted((string)($params['loaderId'] ?? '')));
		$this->pages[$session]['loader'] = $event['loaderId'];

		// The lifecycle events of earlier documents can no longer match anything awaited.
		$this->takeEvents('Page.lifecycleEvent', $session);
	}


	/**
	 * Build a JavaScript expression that returns the element a selector matches.
	 *
	 * @param string $selector CSS selector.
	 * @return string Expression that throws when nothing matches.
	 */
	private static function query(string $selector): string {
		$literal = \json_encode($selector, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

		return '(document.querySelector(' . $literal . ') ?? (() => { throw new Error("No element matches " + ' . $literal . '); })())';
	}


	/**
	 * Queue an event received while waiting for something else.
	 *
	 * @param array<string, mixed> $message Received message; responses to other commands are dropped.
	 * @return void
	 */
	private function queue(array $message): void {
		if (isset($message['method'])) {
			$this->events[] = ['method' => $message['method'], 'params' => $message['params'] ?? [], 'sessionId' => $message['sessionId'] ?? null];
		}
	}


	/**
	 * Read one complete WebSocket message and decode its JSON.
	 *
	 * Behavior:
	 * - Answers pings and joins fragmented messages.
	 *
	 * @param int $deadline hrtime() in nanoseconds after which waiting fails.
	 * @param string $awaited What the caller waits for, for the timeout message.
	 * @return array<string, mixed> Decoded message.
	 * @throws \RuntimeException When the browser closes the connection or nothing arrives in time.
	 */
	private function readMessage(int $deadline, string $awaited): array {
		$payload = '';
		$started = false;

		while (true) {
			[$final, $opcode, $data] = $this->readFrame($deadline, $awaited);

			if ($opcode === 9) {
				$this->writeFrame($data, 10);
				continue;
			}

			if ($opcode === 10) {
				continue;
			}

			if ($opcode === 8) {
				throw new \RuntimeException('The browser closed the DevTools connection while waiting for ' . $awaited . '.');
			}

			if ($opcode === 1 || $opcode === 2) {
				$payload = $data;
				$started = true;
			} elseif ($opcode === 0 && $started) {
				$payload .= $data;
			}

			if ($final && $started) {
				return \json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
			}
		}
	}


	/**
	 * Read one WebSocket frame.
	 *
	 * @param int $deadline hrtime() in nanoseconds after which waiting fails.
	 * @param string $awaited What the caller waits for, for the timeout message.
	 * @return array{0: bool, 1: int, 2: string} FIN flag, opcode and unmasked payload.
	 * @throws \RuntimeException When the connection closes or the frame does not arrive in time.
	 */
	private function readFrame(int $deadline, string $awaited): array {
		$head = $this->take(2, $deadline, $awaited);
		$first = \ord($head[0]);
		$second = \ord($head[1]);
		$length = $second & 0x7F;

		if ($length === 126) {
			$length = \unpack('n', $this->take(2, $deadline, $awaited))[1];
		} elseif ($length === 127) {
			$length = \unpack('J', $this->take(8, $deadline, $awaited))[1];
		}

		$mask = ($second & 0x80) !== 0 ? $this->take(4, $deadline, $awaited) : null;
		$data = $length > 0 ? $this->take($length, $deadline, $awaited) : '';

		if ($mask !== null) {
			$data ^= \substr(\str_repeat($mask, \intdiv($length + 3, 4)), 0, $length);
		}

		return [($first & 0x80) !== 0, $first & 0x0F, $data];
	}


	/**
	 * Write one masked WebSocket frame, as RFC 6455 requires of a client.
	 *
	 * @param string $data Payload.
	 * @param int $opcode Frame opcode: 1 text, 10 pong.
	 * @return void
	 * @throws \RuntimeException When the connection is closed.
	 */
	private function writeFrame(string $data, int $opcode): void {
		$length = \strlen($data);
		$mask = \random_bytes(4);
		$head = \chr(0x80 | $opcode) . match (true) {
			$length < 126   => \chr(0x80 | $length),
			$length < 65536 => \chr(0x80 | 126) . \pack('n', $length),
			default         => \chr(0x80 | 127) . \pack('J', $length),
		};

		$this->write($head . $mask . ($data ^ \substr(\str_repeat($mask, \intdiv($length + 3, 4)), 0, $length)));
	}


	/**
	 * Write raw bytes to the DevTools socket.
	 *
	 * @param string $bytes Bytes.
	 * @return void
	 * @throws \RuntimeException When the connection is closed.
	 */
	private function write(string $bytes): void {
		$offset = 0;
		$length = \strlen($bytes);

		while ($offset < $length) {
			$written = @\fwrite($this->socket, \substr($bytes, $offset));

			if ($written === false || $written === 0) {
				throw new \RuntimeException('The DevTools connection is closed.');
			}

			$offset += $written;
		}
	}


	/**
	 * Take bytes from the received data, reading more as needed.
	 *
	 * @param int $length Number of bytes.
	 * @param int $deadline hrtime() in nanoseconds after which waiting fails.
	 * @param string $awaited What the caller waits for, for the timeout message.
	 * @return string Exactly $length bytes.
	 * @throws \RuntimeException When the connection closes or the bytes do not arrive in time.
	 */
	private function take(int $length, int $deadline, string $awaited): string {
		while (\strlen($this->buffer) < $length) {
			$this->fill($deadline, $awaited);
		}

		$bytes = \substr($this->buffer, 0, $length);
		$this->buffer = (string)\substr($this->buffer, $length);

		return $bytes;
	}


	/**
	 * Read what the socket has, waiting until the deadline for at least one byte.
	 *
	 * @param int $deadline hrtime() in nanoseconds after which waiting fails.
	 * @param string $awaited What the caller waits for, for the timeout message.
	 * @return void
	 * @throws \RuntimeException When the connection closes or nothing arrives in time.
	 */
	private function fill(int $deadline, string $awaited): void {
		$remaining = $deadline - \hrtime(true);

		if ($remaining <= 0) {
			throw new \RuntimeException('Timed out waiting for ' . $awaited . '.');
		}

		$read = [$this->socket];
		$write = null;
		$except = null;

		$ready = @\stream_select($read, $write, $except, \intdiv($remaining, 1_000_000_000), \intdiv($remaining % 1_000_000_000, 1000));

		if ($ready === 0) {
			throw new \RuntimeException('Timed out waiting for ' . $awaited . '.');
		}

		// An interrupted select reads nothing; the caller tries again until the deadline.
		if ($ready === false) {
			return;
		}

		$chunk = @\fread($this->socket, 65536);

		if ($chunk === false || ($chunk === '' && \feof($this->socket))) {
			throw new \RuntimeException('The DevTools connection closed while waiting for ' . $awaited . '.');
		}

		$this->buffer .= $chunk;
	}


}
