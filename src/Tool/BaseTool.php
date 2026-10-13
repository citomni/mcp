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
 * BaseTool: Base class for MCP tools, the transport adapters of the MCP endpoint.
 *
 * A tool is to the MCP endpoint what a controller is to the router: it calls the code that does
 * the work and shapes the result for the model. It relies on the SDK to validate the arguments
 * against its input schema before it runs, so it does not validate them again.
 *
 * Rules:
 * - Declare the definition in DEFINITION, in the wire shape of an MCP tool without "name": the
 *   name is the tool's key in mcp.tools. inputSchema, a JSON Schema of type "object", is
 *   required; title, description, annotations, outputSchema, _meta and icons are optional.
 * - Keep the constructor and init() cheap: a tool may be constructed for requests that never
 *   call it, such as tools/list.
 * - Throw \Mcp\Exception\ToolCallException for a failure the model should see and can act on;
 *   any other throwable is a server error.
 * - Use $client to tell callers apart, for example to bind server-side state to the caller that
 *   created it; never treat it as a credential.
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

	/** Identity that authenticated the request: a secret key such as "mcp.client_token.1", or oauth:<owner_id>:<client_id>; null when authentication is disabled. Never the token. */
	protected readonly ?string $client;


	/**
	 * Inject the application container and the authenticated client, then run the optional init() hook.
	 *
	 * @param App $app Application instance.
	 * @param string|null $client Secret key or OAuth identity that authenticated the request; null when authentication is disabled.
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
	 * @param array<string, mixed> $arguments Arguments the client sent, valid against DEFINITION['inputSchema'].
	 * @param ClientGateway $gateway Requests to the client, such as sampling and elicitation, and notifications.
	 * @return mixed The result: a string, Content objects, an array or object as structured data, or a CallToolResult.
	 * @throws \Mcp\Exception\ToolCallException When the call fails in a way the model should see.
	 * @see \Mcp\Capability\Registry\ToolReference::formatResult()  How the result becomes the content of the tool result.
	 * @see \Mcp\Capability\Registry\ToolReference::extractStructuredContent()  When the result is also sent as structuredContent.
	 */
	abstract public function execute(array $arguments, ClientGateway $gateway): mixed;


}
