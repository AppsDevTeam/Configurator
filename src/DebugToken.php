<?php

declare(strict_types=1);

namespace ADT;

/**
 * Developer cookie of the debug mode (nette-debug): "<slug>.<expiry>.<signature>", an Ed25519
 * signature of the slug, the expiry and the host by the private key of the developer. Servers know
 * only the public keys (APP_DEVELOPERS, "<slug>@<base64 public key>"), nothing secret is deployed,
 * and a leaked cookie works only on its host and only until it expires.
 *
 * No dependencies: bin/debug-token requires this file directly (make debug-key / debug-token of
 * adt/make run it in a throwaway container without the vendor of the project).
 */
final class DebugToken
{
	/** Longest lifetime of a token a server accepts, counted from the moment of the request */
	public const MaxLifetime = 7 * 24 * 3600;

	private const SlugPattern = '[A-Za-z0-9_-]{1,64}';


	/**
	 * @return array{string, string} line for APP_DEVELOPERS ("<slug>@<public key>") and line of the
	 *                               key file of the developer ("<slug>@<secret key>")
	 */
	public static function newKeyPair(string $slug): array
	{
		self::assertSlug($slug);
		$keyPair = sodium_crypto_sign_keypair();
		return [
			$slug . '@' . base64_encode(sodium_crypto_sign_publickey($keyPair)),
			$slug . '@' . base64_encode(sodium_crypto_sign_secretkey($keyPair)),
		];
	}


	/** Line for APP_DEVELOPERS from the line of the key file */
	public static function publicKeyLine(string $secretKeyLine): string
	{
		[$slug, $secretKey] = self::parseSecretKeyLine($secretKeyLine);
		return $slug . '@' . base64_encode(sodium_crypto_sign_publickey_from_secretkey($secretKey));
	}


	public static function create(string $secretKeyLine, string $host, int $lifetime, ?int $now = null): string
	{
		if ($lifetime < 1 || $lifetime > self::MaxLifetime) {
			throw new \InvalidArgumentException('Lifetime must be 1 s to ' . self::MaxLifetime . ' s.');
		}
		[$slug, $secretKey] = self::parseSecretKeyLine($secretKeyLine);
		$expiry = ($now ?? time()) + $lifetime;
		$signature = sodium_crypto_sign_detached(self::message($slug, $expiry, $host), $secretKey);
		return $slug . '.' . $expiry . '.' . self::base64Url($signature);
	}


	/**
	 * @param string[] $developers lines of APP_DEVELOPERS, other lines (e.g. the former bcrypt hashes)
	 *                             are ignored
	 */
	public static function verify(mixed $token, array $developers, string $host, ?int $now = null): bool
	{
		if (
			!is_string($token)
			|| !preg_match('~^(' . self::SlugPattern . ')\.([0-9]{1,12})\.([A-Za-z0-9_-]{86})$~D', $token, $m)
		) {
			return false;
		}
		[, $slug, $expiry, $signature] = $m;
		$now ??= time();
		if ((int) $expiry <= $now || (int) $expiry > $now + self::MaxLifetime) {
			return false;
		}

		foreach ($developers as $developer) {
			if (!is_string($developer) || !str_starts_with($developer, $slug . '@')) {
				continue;
			}
			$publicKey = base64_decode(substr($developer, strlen($slug) + 1), true);
			if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
				continue;
			}
			$signature = base64_decode(strtr($signature, '-_', '+/'), true);
			return $signature !== false
				&& strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
				&& sodium_crypto_sign_verify_detached($signature, self::message($slug, (int) $expiry, $host), $publicKey);
		}
		return false;
	}


	/** Host without the port and the trailing dot, lowercase */
	public static function normalizeHost(string $host): string
	{
		return rtrim(strtolower((string) preg_replace('~:[0-9]+$~', '', $host)), '.');
	}


	private static function message(string $slug, int $expiry, string $host): string
	{
		return "nette-debug\n" . $slug . "\n" . $expiry . "\n" . self::normalizeHost($host);
	}


	/** @return array{string, string} */
	private static function parseSecretKeyLine(string $line): array
	{
		$parts = explode('@', trim($line), 2);
		$secretKey = base64_decode($parts[1] ?? '', true);
		if ($secretKey === false || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
			throw new \InvalidArgumentException('Invalid key file, expected "<slug>@<base64 secret key>".');
		}
		self::assertSlug($parts[0]);
		return [$parts[0], $secretKey];
	}


	private static function assertSlug(string $slug): void
	{
		if (!preg_match('~^' . self::SlugPattern . '$~D', $slug)) {
			throw new \InvalidArgumentException('Slug may contain only A-Z, a-z, 0-9, _ and -.');
		}
	}


	private static function base64Url(string $data): string
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}
}
