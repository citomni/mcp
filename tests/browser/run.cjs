'use strict';

// Real TLS and Chromium exercise browser-generated Origin, CSP redirects and cookies.
const assert = require('node:assert/strict');
const {spawn, execFileSync} = require('node:child_process');
const {mkdtempSync, readFileSync, rmSync} = require('node:fs');
const {tmpdir} = require('node:os');
const {join} = require('node:path');
const {createHash} = require('node:crypto');
const http = require('node:http');
const https = require('node:https');
const {createInterface} = require('node:readline');
const {chromium} = require('playwright');

const listen = server => new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const close = server => new Promise(resolve => server.close(resolve));

(async () => {
	const temp = mkdtempSync(join(tmpdir(), 'citomni-oauth-browser-'));
	let browser, php, proxy, callback;
	let stderr = '';
	try {
		execFileSync(process.env.OPENSSL_BINARY || 'openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
			'-subj', '/CN=127.0.0.1', '-keyout', join(temp, 'key.pem'), '-out', join(temp, 'cert.pem')], {stdio: 'pipe'});
		const tls = {key: readFileSync(join(temp, 'key.pem')), cert: readFileSync(join(temp, 'cert.pem'))};
		const callbacks = [];
		callback = https.createServer(tls, (req, res) => {
			callbacks.push({url: req.url, referer: req.headers.referer});
			res.writeHead(200, {'Content-Type': 'text/html'}).end('<h1>Callback received</h1>');
		});
		await listen(callback);
		const callbackUrl = `https://127.0.0.1:${callback.address().port}/callback`;
		let backend;
		const posts = [];
		proxy = https.createServer(tls, (req, res) => {
			if (req.method === 'POST') posts.push({origin: req.headers.origin, site: req.headers['sec-fetch-site']});
			const target = http.request({host: '127.0.0.1', port: backend, path: req.url, method: req.method, headers: req.headers}, incoming => {
				res.writeHead(incoming.statusCode, incoming.headers);
				incoming.pipe(res);
			});
			target.on('error', error => res.writeHead(502).end(error.message));
			req.pipe(target);
		});
		await listen(proxy);
		const issuer = `https://127.0.0.1:${proxy.address().port}`;
		php = spawn(process.env.PHP_BINARY || 'php', [join(__dirname, 'fixture.php'), issuer, callbackUrl], {stdio: ['pipe', 'pipe', 'pipe']});
		php.stderr.on('data', data => { stderr += data; });
		const fixture = await new Promise((resolve, reject) => {
			const lines = createInterface({input: php.stdout});
			const timer = setTimeout(() => reject(new Error('PHP fixture timed out')), 15000);
			lines.once('line', line => { clearTimeout(timer); try { resolve(JSON.parse(line)); } catch { reject(new Error(line)); } });
			php.once('exit', code => { clearTimeout(timer); if (code) reject(new Error(`PHP fixture exited ${code}: ${stderr}`)); });
		});
		backend = fixture.port;
		browser = await chromium.launch({headless: true, ...(process.env.CHROMIUM_EXECUTABLE ? {executablePath: process.env.CHROMIUM_EXECUTABLE} : {})});
		console.log(`Chromium ${browser.version()}`);
		const context = await browser.newContext({ignoreHTTPSErrors: true});
		const page = await context.newPage();
		const errors = [];
		page.on('console', message => { if (/form-action|Content Security Policy/.test(message.text())) errors.push(message.text()); });
		const authorize = () => issuer + '/mcp/oauth/authorize?' + new URLSearchParams({
			response_type: 'code', client_id: fixture.client, redirect_uri: callbackUrl, resource: issuer + '/mcp',
			scope: 'repo.read', state: 'browser-state', code_challenge_method: 'S256',
			code_challenge: createHash('sha256').update('a'.repeat(64)).digest('base64url'),
		});
		await page.goto(authorize());
		await page.locator('#password').fill('wrong');
		const wrong = page.waitForResponse(r => r.url() === issuer + '/mcp/oauth/authorize' && r.request().method() === 'POST');
		await page.locator('button[value="approve"]').click();
		assert.equal((await wrong).status(), 403);
		assert.equal(posts.at(-1).origin, issuer, 'Browser must generate the issuer Origin');
		assert.equal(posts.at(-1).site, 'same-origin');
		assert.equal(callbacks.length, 0);
		console.log('PASS Same-origin browser submission and invalid password');
		await page.locator('#password').fill(fixture.password);
		await Promise.all([page.waitForURL(callbackUrl + '?*', {timeout: 10000}), page.locator('button[value="approve"]').click()]);
		let query = new URL(page.url()).searchParams;
		assert.ok(query.get('code'));
		assert.equal(query.get('state'), 'browser-state');
		assert.equal(query.get('iss'), issuer);
		assert.equal(callbacks.at(-1).referer, undefined, 'Cross-origin callback must not receive Referer');
		assert.deepEqual(errors, [], 'CSP must allow the registered callback');
		console.log('PASS Approval redirects to the separate callback origin with code, state and issuer');
		assert.ok(!(await context.cookies(issuer)).some(cookie => cookie.name === '__Host-citomni_mcp_oauth'));
		console.log('PASS Approval cookie cleared after redirect');
		await page.goto(authorize());
		await Promise.all([page.waitForURL(callbackUrl + '?*', {timeout: 10000}), page.locator('button[value="deny"]').click()]);
		query = new URL(page.url()).searchParams;
		assert.equal(query.get('error'), 'access_denied');
		assert.equal(query.get('iss'), issuer);
		assert.equal(query.get('state'), 'browser-state');
		assert.equal(query.get('code'), null);
		assert.deepEqual(errors, []);
		console.log('PASS Denial redirects without issuing a code');
		await page.goto(authorize());
		const violation = page.waitForEvent('console', {predicate: message => /form-action/.test(message.text()), timeout: 5000});
		// The plain-HTTP backend is local and deliberately outside the HTTPS source list.
		await page.evaluate(url => {
			document.querySelector('form').action = url;
			document.querySelector('form').requestSubmit();
		}, `http://127.0.0.1:${backend}/forbidden`);
		await violation;
		assert.ok(page.url().startsWith(issuer + '/mcp/oauth/authorize?'));
		console.log('PASS CSP blocks submission outside the configured HTTPS origins');
		console.log('5 passed, 0 failed');
	} finally {
		if (browser) await browser.close();
		if (php && php.exitCode === null) {
			const exited = new Promise(resolve => php.once('exit', resolve));
			php.stdin.end('\n');
			await exited;
		}
		if (proxy) await close(proxy);
		if (callback) await close(callback);
		rmSync(temp, {recursive: true, force: true});
	}
})().catch(error => { console.error('FAIL', error.message); process.exitCode = 1; });
