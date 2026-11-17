<?php

declare(strict_types=1);

namespace ADT\Configurator\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An exception of a real web request in production mode (php -S + tests/fixtures/web/index.php): in CLI
 * Tracy leaves out the whole HTTP request section (headers, cookies, query, POST), which is where most
 * of the secrets of a web request are.
 */
final class BootstrapWebTest extends TestCase
{
	private string $root;

	/** @var resource|null */
	private $server = null;

	/** @var array<string, string> */
	private array $secrets = [];

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/adt-bootstrap-web-test-' . uniqid();
		mkdir($this->root . '/log', 0777, true);
		mkdir($this->root . '/temp');

		foreach (['DB_PASSWORD', 'MAILAPI_KEY', 'ZOHO_REFRESH_TOKEN', 'developer', 'session', 'app-debug',
			'remember', 'authorization', 'api-key', 'query-token', 'post-password'] as $name) {
			$this->secrets[$name] = 'sec' . bin2hex(random_bytes(8));
		}

		$this->write('.env', implode("\n", [
			'STAGE=local',
			'APP_DEBUG=0',
			'APP_DEVELOPERS=["ssh-ed25519 ' . $this->secrets['developer'] . '"]',
			'APP_ENV=',
			'APP_TEST=0',
			'PROJECT_URL=visible-project-url',
			'DB_PASSWORD=' . $this->secrets['DB_PASSWORD'],
			'MAILAPI_KEY=' . $this->secrets['MAILAPI_KEY'],
			'PREPROD_IPS=78.80.93.27, 203.0.113.0/24',
		]) . "\n");
		$this->write('config/common.neon', "parameters:\n\tfrom: common\n");
		$this->write('config/interface/ui.neon', "parameters:\n\tinterface: ui\n");
		$this->write('config/stage/local.neon', "parameters:\n\tstage: local\n");
		$this->write('app/Bootstrap.php', <<<'PHP'
			<?php
			namespace FixtureApp;

			class Bootstrap extends \ADT\Bootstrap
			{
				protected function init(): void
				{
					if (!getenv('FIXTURE_ERROR_LOGGER')) {
						$this->errorEmail = null;
					}
					// nette/http, latte ... are not dependencies of the library
					$this->configurator->defaultExtensions = array_intersect_key(
						$this->configurator->defaultExtensions,
						array_flip(['php', 'constants', 'extensions', 'decorator', 'di', 'search', 'inject']),
					);
				}
			}
			PHP);
	}

	protected function tearDown(): void
	{
		if ($this->server !== null) {
			proc_terminate($this->server);
			proc_close($this->server);
		}
		exec('rm -rf ' . escapeshellarg($this->root));
	}

	/** @return array<string, array{bool}> */
	public static function loggers(): array
	{
		return ['Tracy' => [false], 'ErrorLogger' => [true]];
	}

	#[DataProvider('loggers')]
	public function testSecretsOfWebRequestAreHiddenInErrorLog(bool $errorLogger): void
	{
		$port = $this->startServer([
			'FIXTURE_ROOT' => $this->root,
			'FIXTURE_ERROR_LOGGER' => $errorLogger ? '1' : '',
			// FPM with clear_env = no: the environment of the container is in $_SERVER and $_ENV too
			'ZOHO_REFRESH_TOKEN' => $this->secrets['ZOHO_REFRESH_TOKEN'],
			'VISIBLE_ENV' => 'visible-env',
		]);

		$response = $this->request($port, '/orders?token=' . $this->secrets['query-token'] . '&page=visible-query', [
			'Cookie: PHPSESSID=' . $this->secrets['session'] . '; app-debug=' . $this->secrets['app-debug']
				. '; remember_me=' . $this->secrets['remember'] . '; theme=visible-cookie',
			'Authorization: Bearer ' . $this->secrets['authorization'],
			'X-Api-Key: ' . $this->secrets['api-key'],
			'X-Visible: visible-header',
		], ['password' => $this->secrets['post-password'], 'note' => 'visible-post']);

		// production mode: the error page, not the BlueScreen
		$this->assertStringContainsString('500', $response);
		$this->assertStringNotContainsString('fixture-exception', $response);

		// The markdown report of plain Tracy prints the request headers raw (Cookie, Authorization, X-Api-Key)
		// and call arguments without their names ($password), so the scrubber never sees them - that is why
		// it is checked (and attached to e-mails) only with ErrorLogger, which writes a scrubbed one itself.
		$reports = [
			'html' => $this->readLog('exception--*.html'),
			'log' => $this->readLog('*.log'),
		] + ($errorLogger ? ['md' => $this->readLog('exception--*.md'), 'mail' => $this->readLog('mail.txt')] : []);
		// Known gap: the URL (title of the report, REQUEST_URI, QUERY_STRING, the line of exception.log) is
		// printed raw by Tracy from $_SERVER, a scrubber of keys cannot reach into it. Checked is that the
		// value is nowhere else ($_GET, arguments).
		$reports = str_replace('token=' . $this->secrets['query-token'], 'token=(url)', $reports);
		$this->assertContainsMarkers($reports['html'], 'html', ['fixture-exception visible-post', 'visible-header',
			'theme', 'visible-query', 'visible-env']);
		if ($errorLogger) {
			$this->assertContainsMarkers($reports['md'], 'md', ['fixture-exception visible-post', 'visible-header',
				'visible-query']);
			$this->assertContainsMarkers($reports['mail'], 'mail', ['fixture-exception visible-post', 'visible-header']);
		}
		$this->assertContainsMarkers($reports['log'], 'log', ['fixture-exception visible-post']);

		$leaks = array_map(fn(string $report): array => array_keys(array_filter(
			$this->secrets,
			fn(string $value): bool => str_contains($report, $value),
		)), $reports);
		$this->assertSame([], array_filter($leaks), 'secrets in the reports');
	}

	public function testPreprodOnlyForAllowedIps(): void
	{
		$port = $this->startServer(['FIXTURE_ROOT' => $this->root]);

		$denied = $this->request($port, '/', ['X-Forwarded-Port: 4434', 'X-Real-IP: 1.2.3.4'], []);
		$this->assertStringContainsString(' 403 ', $denied);
		$this->assertSame([], glob($this->root . '/log/exception--*') ?: [], 'the application must not run');

		// the application runs (and throws the fixture exception): allowed IP, private client, not preprod
		foreach ([
			['X-Forwarded-Port: 4434', 'X-Real-IP: 203.0.113.7'],
			['X-Forwarded-Port: 4434'],
			['X-Forwarded-Port: 443', 'X-Real-IP: 1.2.3.4'],
		] as $headers) {
			$this->assertStringContainsString(' 500 ', $this->request($port, '/', $headers, []), implode(', ', $headers));
		}
	}

	/** @param list<string> $markers */
	private function assertContainsMarkers(string $content, string $file, array $markers): void
	{
		$missing = array_values(array_filter($markers, fn(string $marker): bool => !str_contains($content, $marker)));
		$this->assertSame([], $missing, "missing in $file (was the report generated?)");
	}

	/** @param array<string, string> $env */
	private function startServer(array $env): int
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$this->assertNotFalse($socket);
		$port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
		fclose($socket);

		$docRoot = __DIR__ . '/fixtures/web';
		$this->server = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docRoot, $docRoot . '/index.php'],
			[0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $this->root . '/server.log', 'w']],
			$pipes,
			null,
			$env + ['PATH' => (string) getenv('PATH')],
		) ?: null;
		$this->assertNotNull($this->server);

		for ($i = 0; $i < 100; $i++) {
			if ($connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1)) {
				fclose($connection);
				return $port;
			}
			usleep(50_000);
		}
		$this->fail('php -S did not start: ' . file_get_contents($this->root . '/server.log'));
	}

	/**
	 * @param list<string> $headers
	 * @param array<string, string> $post
	 */
	private function request(int $port, string $path, array $headers, array $post): string
	{
		$response = file_get_contents('http://127.0.0.1:' . $port . $path, false, stream_context_create(['http' => [
			'method' => 'POST',
			'header' => array_merge($headers, ['Content-Type: application/x-www-form-urlencoded']),
			'content' => http_build_query($post),
			'ignore_errors' => true,
			'timeout' => 30,
		]]));
		$this->assertIsString($response);
		return implode("\n", $http_response_header ?? []) . "\n" . $response;
	}

	private function readLog(string $pattern): string
	{
		$files = glob($this->root . '/log/' . $pattern) ?: [];
		$this->assertNotSame([], $files, "no $pattern in log/: " . implode(', ', scandir($this->root . '/log') ?: [])
			. "\n" . file_get_contents($this->root . '/server.log'));
		return implode("\n", array_map(fn(string $file): string => (string) file_get_contents($file), $files));
	}

	private function write(string $file, string $content): void
	{
		$path = $this->root . '/' . $file;
		@mkdir(dirname($path), 0777, true);
		file_put_contents($path, $content);
	}
}
