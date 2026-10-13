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

namespace CitOmni\Mcp\Support\OAuth;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * ConsentPage: Support class that renders the owner approval page.
 *
 * Renders a small self-contained HTML page without the host's TemplateEngine, theme or session,
 * so that the approval page works the same in every host. Its labels are cfg, so that a host can
 * translate it.
 *
 * Rules:
 * - Escape every value that comes from cfg or from the caller.
 * - Keep the page self-contained: inline styles only, no scripts and no external resources.
 */
final class ConsentPage {

	/**
	 * Build the approval page response.
	 *
	 * @param Config $config Validated OAuth cfg.
	 * @param string $id Pending approval ID.
	 * @param string $csrf CSRF value for the form.
	 * @param string $error Message shown above the form; '' shows none.
	 * @param int $status HTTP status.
	 * @return ResponseInterface An HTML response without secrets.
	 */
	public static function response(Config $config, string $id, string $csrf, string $error = '', int $status = 200): ResponseInterface {
		$escape = static fn(string $s): string => \htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$text = $escape($config->values['consent_text']);
		$resource = $escape($config->resource);
		$labels = $config->values['texts'];
		$message = $error === '' ? '' : '<p role="alert">' . $escape($error) . '</p>';
		$html = '<!doctype html><html lang="' . $escape($labels['lang']) . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>' . $escape($labels['title']) . '</title><style>body{font:18px/1.5 system-ui,sans-serif;background:#f4f5f7;color:#19232d;margin:0;padding:8vh 20px}main{max-width:560px;margin:auto;background:white;padding:32px;border-radius:12px}h1{font-size:28px;margin-top:0}small{overflow-wrap:anywhere}label{display:block;margin-top:24px}input{box-sizing:border-box;width:100%;padding:12px;font:inherit;margin:8px 0 20px}button{padding:12px 18px;font:inherit;cursor:pointer;border:1px solid #ccd2d8;border-radius:6px;margin-right:8px}button[value=approve]{background:#154d78;color:white;border-color:#154d78}[role=alert]{color:#a21b1b}</style>'
			. '<main><h1>' . $escape(\str_replace('{client}', $config->values['client_name'], $labels['heading'])) . '</h1><p>' . $text . '</p><small>' . $resource . '</small>' . $message
			. '<form method="post" action="/mcp/oauth/authorize"><input type="hidden" name="request_id" value="' . $escape($id) . '">'
			. '<input type="hidden" name="csrf" value="' . $escape($csrf) . '"><label for="password">' . $escape($labels['password']) . '</label>'
			. '<input id="password" name="password" type="password" autocomplete="current-password" maxlength="1024">'
			. '<button type="submit" name="decision" value="approve">' . $escape($labels['approve']) . '</button>'
			. '<button type="submit" name="decision" value="deny">' . $escape($labels['deny']) . '</button></form></main></html>';
		return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], $html);
	}


}
