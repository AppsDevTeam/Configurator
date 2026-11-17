<?php

namespace ADT;

use ADT\Tracy\LockFreeFileSession;
use Exception;
use josegonzalez\Dotenv\Loader;
use Nette\DI\ContainerLoader;
use Tracy\Debugger;

final class Configurator extends \Nette\Bootstrap\Configurator
{
	private static array $parameters = [];

	private string $configDirectory = '';

	private ?bool $autoRebuild = null;

	private bool $debugModeByCookie = false;


	public function loadEnv(string $envDirectory, array $additionalFiles = []): self
	{
		$envs = [];
		foreach (array_merge($additionalFiles, ['.env']) as $_envFile) {
			$envs = array_merge(
				(new Loader($envDirectory . '/' . $_envFile))
					->parse()
					->skipExisting()
					->toArray(),
				$envs
			);
		}

		// arguments of array_merge has to be in this order
		$_ENV = array_merge($envs, $_ENV);

		foreach ($_ENV as &$_value) {
			if (!is_string($_value)) {
				continue;
			}

			$_value = $this->convertString($_value);
		}

		// arguments of array_intersect_key has to be in this order
		$this->addStaticParameters(['env' => array_intersect_key($_ENV, $envs)]);

		return $this;
	}


	/**
	 * Maintenance window of a deploy (recipe of adt/docker-deployer, README "Maintenance during
	 * migrations"): while the deploy runs migrations, the file MAINTENANCE_FILE exists on all
	 * servers, so that the application holds no metadata locks of the tables being changed.
	 * Commands of the deploy have MAINTENANCE_BYPASS=1 - read by getenv(), $_ENV holds the empty
	 * value from .env. Call after loadEnv().
	 *
	 * @phpstan-impure
	 */
	public static function isMaintenance(): bool
	{
		$file = $_ENV['MAINTENANCE_FILE'] ?? getenv('MAINTENANCE_FILE');
		if (!$file || getenv('MAINTENANCE_BYPASS')) {
			return false;
		}

		clearstatcache(true, $file);
		return file_exists($file);
	}


	/**
	 * Handles the maintenance window before the DI container is created (no database connection):
	 * - web: 503 with Retry-After ($template = own page, otherwise a default one)
	 * - console: ends with exit code 0 (cron jobs skip a run), except $waitingCommands, which wait
	 *   until the maintenance ends and then continue - supervisor would restart a consumer that
	 *   exits in a loop
	 *
	 * @param list<string> $waitingCommands
	 */
	public function handleMaintenance(?string $template = null, array $waitingCommands = ['background-queue:consume']): void
	{
		if (!self::isMaintenance()) {
			return;
		}

		if (self::isCli()) {
			// Anywhere in argv, not just argv[1]: `bin/console -v background-queue:consume` is the consumer too
			if (array_intersect($waitingCommands, array_slice($_SERVER['argv'] ?? [], 1)) !== []) {
				while (self::isMaintenance()) {
					sleep(5);
				}
				return;
			}
			echo "Maintenance (database migrations) - the command is skipped.\n";
			exit(0);
		}

		http_response_code(503);
		header('Retry-After: 60');
		header('Cache-Control: no-store');
		require $template ?? __DIR__ . '/maintenance.phtml';
		exit;
	}


	/**
	 * Preprod (X-Forwarded-Port 4434 - the same header by which nginx serves release/www instead of
	 * current/www) only for PREPROD_IPS of .env (comma-separated IPs or CIDRs, empty = open to all)
	 * and private addresses: the smoke check of the deploy comes from the docker bridge, the VPN
	 * from a private range. Web 403, the console is not affected. Call after loadEnv().
	 */
	public function handlePreprodAccess(): void
	{
		if (self::isCli() || self::isPreprodAllowed($_SERVER, (string) ($_ENV['PREPROD_IPS'] ?? ''))) {
			return;
		}

		http_response_code(403);
		header('Cache-Control: no-store');
		header('Content-Type: text/plain; charset=utf-8');
		echo "Forbidden\n";
		exit;
	}


	/**
	 * @param array<string, mixed> $server
	 */
	public static function isPreprodAllowed(array $server, string $allowedIps): bool
	{
		if (($server['HTTP_X_FORWARDED_PORT'] ?? null) !== '4434' || trim($allowedIps) === '') {
			return true;
		}

		// The edge proxy (nginx-proxy) connects from a private address and always overwrites X-Real-IP
		// with its client; works with real_ip of nginx on (REMOTE_ADDR is the client already) and off
		$ip = (string) ($server['REMOTE_ADDR'] ?? '');
		$realIp = (string) ($server['HTTP_X_REAL_IP'] ?? '');
		if (self::isPrivateIp($ip) && filter_var($realIp, FILTER_VALIDATE_IP)) {
			$ip = $realIp;
		}

		if (self::isPrivateIp($ip)) {
			return true;
		}
		foreach (explode(',', $allowedIps) as $range) {
			if (self::ipInRange($ip, trim($range))) {
				return true;
			}
		}
		return false;
	}


	private static function isPrivateIp(string $ip): bool
	{
		return filter_var($ip, FILTER_VALIDATE_IP) !== false
			&& filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
	}


	/** $range = IP or CIDR; an invalid one matches nothing (a typo closes preprod, does not open it) */
	private static function ipInRange(string $ip, string $range): bool
	{
		[$subnet, $bits] = explode('/', $range, 2) + [1 => null];
		if (!filter_var($ip, FILTER_VALIDATE_IP) || !filter_var($subnet, FILTER_VALIDATE_IP)) {
			return false;
		}
		$ipBin = (string) inet_pton($ip);
		$subnetBin = (string) inet_pton($subnet);
		$maxBits = strlen($ipBin) * 8;
		if (strlen($subnetBin) !== strlen($ipBin) || ($bits !== null && (!ctype_digit($bits) || (int) $bits > $maxBits))) {
			return false;
		}

		$bits = $bits === null ? $maxBits : (int) $bits;
		$bytes = intdiv($bits, 8);
		if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
			return false;
		}
		$rest = $bits % 8;
		return $rest === 0 || ((ord($ipBin[$bytes]) ^ ord($subnetBin[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0;
	}


	/**
	 * @throws Exception
	 */
	public function addConfig(string|array|null $config): static
	{
		$config = (array) $config;

		$config = array_filter($config);

		foreach ($config as $_config) {
			$_configPath = $this->configDirectory ? $this->configDirectory . '/' . $_config . '.neon' : $_config;

			if (!file_exists($_configPath)) {
				throw new Exception('Config file "' . $_configPath . '" does not exist.');
			}

			$parts = explode('/', $_config);
			if (count($parts) === 2) {
				if (isset(self::$parameters[$parts[0]])) {
					throw new Exception('You are trying to include more "' . $parts[0] . '" configs.');
				}

				self::$parameters[$parts[0]] = $parts[1];
			}

			parent::addConfig($_configPath);
		}

		return $this;
	}


	/**
	 * In debug mode the deferred bar content is stored by LockFreeFileSession instead of Tracy's
	 * FileSession, whose lock held for the whole request makes concurrent AJAX requests of one
	 * browser wait for each other. A storage set by the project before is kept.
	 */
	public function enableTracy(?string $logDirectory = null, ?string $email = null): void
	{
		Debugger::$logSeverity = E_ALL;
		if ($this->staticParameters['debugMode'] && !self::isCli()) {
			try {
				Debugger::setSessionStorage(new LockFreeFileSession(self::getTracySessionDirectory()));
			} catch (Exception) {
				// the project has set its own storage
			}
		}
		parent::enableTracy($logDirectory, $email);
	}


	/** The same directory Tracy would pick for its FileSession */
	private static function getTracySessionDirectory(): string
	{
		$dir = (string) session_save_path();
		if (!@is_dir($dir)) { // @ - the path may be outside open_basedir
			$dir = sys_get_temp_dir();
		}
		return $dir . '/tracy-session';
	}


	/**
	 * Debug mode enabled by a developer cookie (list of developer keys) means a deployed server,
	 * whose code does not change between deploys - the DI container is then never rebuilt, the same
	 * as in production mode. With auto-rebuild nette/di invalidates the container in opcache on every
	 * request (ContainerLoader::includeFreshFile), so each debug request recompiles it and wastes
	 * opcache memory until opcache restarts.
	 */
	public function setDebugMode(bool|string|array $value): static
	{
		$this->debugModeByCookie = !is_bool($value);
		return parent::setDebugMode($value);
	}


	/**
	 * Overrides whether the DI container is rebuilt when its sources change (default: debug mode,
	 * except debug mode enabled by a developer cookie).
	 */
	public function setAutoRebuild(bool $autoRebuild): self
	{
		$this->autoRebuild = $autoRebuild;
		return $this;
	}


	public function loadContainer(): string
	{
		$loader = new ContainerLoader(
			$this->getCacheDirectory() . '/nette.configurator',
			$this->autoRebuild ?? ($this->staticParameters['debugMode'] && !$this->debugModeByCookie),
		);
		return $loader->load(
			$this->generateContainer(...),
			$this->generateContainerKey(),
		);
	}


	public function setConfigDirectory(string $configDirectory): self
	{
		$this->configDirectory = $configDirectory;
		return $this;
	}


	/**
	 * Debug mode of a deployed server: the cookie app-debug with a token signed by the SSH key of
	 * a developer from the list (APP_DEVELOPERS, public SSH keys), see DebugToken, made by
	 * `make debug-token` of adt/make. Only when the edge proxy allows the IP of the client by the header
	 * X-App-Debug-Allowed: 1 (nginx-proxy of server-management-scripts, NGINX_PROXY_DEBUG_IPS) - the
	 * proxy always overwrites it, without the proxy (locally, directly) the cookie does nothing.
	 *
	 * @param string|string[]|null $list
	 * @throws Exception
	 */
	public static function detectDebugMode(string|array|null $list = null): bool
	{
		if ($list === null) {
			return false;
		}

		if (php_sapi_name() === 'cli') {
			throw new Exception('Not allowed for CLI.');
		}

		return ($_SERVER['HTTP_X_APP_DEBUG_ALLOWED'] ?? null) === '1' && DebugToken::verify(
			$_COOKIE[DebugToken::CookieName] ?? null,
			(array) $list,
			(string) ($_SERVER['HTTP_HOST'] ?? ''),
		);
	}


	public static function isCli(): bool
	{
		return php_sapi_name() === 'cli';
	}


	public static function get($name): string
	{
		return self::$parameters[$name] ?? '';
	}

	private function convertString(string $str): float|bool|int|string|null
	{
		// Pokud řetězec obsahuje pouze číslice
		if (ctype_digit($str)) {
			// když začíná 0 a má víc jak 1 znak, vratíme string
			if (str_starts_with($str, '0') && strlen($str) > 1) {
				return $str;
			}
			return (int)$str; // jinak vratíme integer
		}

		// Pokud řetězec obsahuje číslice a případně jednu desetinnou tečku, vratíme float
		if (preg_match('/^\d+\.\d+$/', $str)) {
			return (float)$str;
		}

		// Pro "true" a "false" vrátíme boolean
		if (strtolower($str) === 'true') {
			return true;
		}

		if (strtolower($str) === 'false') {
			return false;
		}

		// V opačném případě vratíme původní řetězec
		return $str;
	}

	/**
	 * @throws Exception
	 */
	public static function getConfigByUrl($urls): string
	{
		if (! isset($_SERVER['HTTP_HOST'])) {
			throw new Exception('Variable \'$_SERVER[HTTP_HOST]\' is not set.');
		}

		if (! isset($_SERVER['REQUEST_URI'])) {
			throw new Exception('Variable \'$_SERVER[REQUEST_URI]\' is not set.');
		}

		// Exclude server port and parameters.
		$requestUrl = explode(':', $_SERVER['HTTP_HOST'])[0] . explode('?', $_SERVER['REQUEST_URI'])[0];

		foreach ($urls as $url => $env) {
			if (str_starts_with($url, '^')) {
				// Key in $this->urls can be regex (starts with '^').
				if (preg_match("\x01$url\x01", $requestUrl)) {
					return $env;
				}
			} else {
				// Or just a regular string.
				if (str_starts_with($requestUrl . '/', $url . '/')) {
					// $requestUrl starts with $url and continues with a slash or ends.
					return $env;
				}
			}
		}

		throw new Exception('No env was found.');
	}
}
