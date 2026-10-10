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

namespace CitOmni\Mcp\Tool;

use CitOmni\Kernel\App;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;

/**
 * BaseTool: Base class for MCP tools registered in cfg mcp.tools.
 *
 * A tool is a transport adapter, like a controller or a command: it validates
 * nothing the SDK already validated, calls the code that does the work, and
 * shapes the result for the model.
 *
 * Behavior:
 * - The tool's name is its key in mcp.tools. Everything else the client sees in
 *   tools/list comes from DEFINITION, in the wire shape of an MCP Tool:
 *   1) inputSchema (required): JSON Schema of type "object" for the arguments
 *   2) title, description, annotations, outputSchema, _meta, icons (optional)
 * - The SDK validates the arguments against inputSchema before execute() runs.
 *   A violation never reaches the tool; the client gets JSON-RPC -32602.
 * - Constructed per request as new $class($app, $client); init() runs when
 *   defined. $client is the secret key whose token authenticated the request,
 *   e.g. "mcp.client_token.1", or null when authentication is disabled. A tool
 *   uses it to tell callers apart, for example to bind state to the client
 *   that created it; it is never the token.
 *
 * Notes:
 * - DEFINITION must not contain "name"; ServerFactory refuses one that does.
 * - Keep the constructor and init() cheap: every request that builds the server
 *   constructs every registered tool, also for tools/list.
 *
 * Typical usage:
 *   final class EchoText extends BaseTool {
 *       public const array DEFINITION = [
 *           'description' => 'Return the given text unchanged.',
 *           'inputSchema' => [
 *               'type' => 'object',
 *               'properties' => ['text' => ['type' => 'string']],
 *               'required' => ['text'],
 *           ],
 *           'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
 *       ];
 *
 *       public function execute(array $arguments, ClientGateway $gateway): string {
 *           return $arguments['text'];
 *       }
 *   }
 */
abstract class BaseTool implements ToolHandlerInterface {

	/**
	 * The tool definition in MCP wire shape, without "name".
	 *
	 * @var array<string, mixed>
	 */
	public const array DEFINITION = [];

	/** Application container (configuration, service map, utilities). */
	protected App $app;

	/** Secret key that authenticated the request, e.g. "mcp.client_token.1"; null when authentication is disabled. */
	protected readonly ?string $client;


	/**
	 * Inject the application container and the authenticated client, then run the optional init() hook.
	 *
	 * @param App $app Application instance.
	 * @param string|null $client Secret key that authenticated the request; null when authentication is disabled.
	 */
	public function __construct(App $app, ?string $client = null) {
		$this->app = $app;
		$this->client = $client;

		if (\method_exists($this, 'init')) {
			$this->init();
		}
	}


	/**
	 * Run the tool.
	 *
	 * Behavior:
	 * - $arguments holds the arguments the client sent, already valid against
	 *   DEFINITION['inputSchema'].
	 * - The return value becomes the result content:
	 *   1) A string or other scalar: one text block
	 *   2) A \Mcp\Schema\Content\Content object, or a list of them: as is
	 *   3) An array or object: one text block of pretty-printed JSON, and the
	 *      same data as structuredContent (a list only in protocol revisions
	 *      that allow it)
	 *   4) A \Mcp\Schema\Result\CallToolResult: as is
	 * - Throw \Mcp\Exception\ToolCallException for a failure the model should
	 *   see and can act on, such as a file that does not exist. The client gets a
	 *   result with isError true and the exception message as text.
	 * - Any other throwable is a server failure. The client gets JSON-RPC -32603
	 *   without details, and the SDK logs the exception (mcp.log.sdk_level).
	 *
	 * @param array<string, mixed> $arguments Validated tool arguments.
	 * @param ClientGateway $gateway Requests to the client (sampling, elicitation) and notifications.
	 * @return mixed Tool result, see above.
	 * @throws \Mcp\Exception\ToolCallException When the call fails in a way the model should see.
	 */
	abstract public function execute(array $arguments, ClientGateway $gateway): mixed;

}
