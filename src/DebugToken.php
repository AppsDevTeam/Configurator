<?php

declare(strict_types=1);

namespace ADT;

/**
 * Developer cookie of the debug mode (nette-debug): "<expiry>.<host>.<signature>", a signature of the
 * expiry and the host ("*" = every host) by the SSH key (ssh-ed25519) of a developer, made by
 * `ssh-keygen -Y sign -n nette-debug` (SSHSIG). Servers know only the public SSH keys (APP_DEVELOPERS,
 * "<slug>@ssh-ed25519 <base64>"), nothing secret is deployed, and a leaked cookie stops working when
 * it expires. On top of that ADT\Configurator requires the header X-Nette-Debug-Allowed: 1 of the
 * edge proxy, which sends it only to the IPs allowed on the server.
 *
 * No dependencies: bin/debug-token requires this file directly (make debug-token of adt/make runs it
 * in a throwaway container without the vendor of the project).
 */
final class DebugToken
{
	public const Namespace = 'nette-debug';

	/** Longest lifetime of a token a server accepts, counted from the moment of the request */
	public const MaxLifetime = 366 * 24 * 3600;

	/** Host of a token valid on every host */
	public const AnyHost = '*';


	/** Text the developer signs */
	public static function message(int $expiry, string $host): string
	{
		return self::Namespace . "\n" . $expiry . "\n" . self::normalizeHost($host);
	}


	/**
	 * Data an SSH signature (SSHSIG) of the message actually signs, see PROTOCOL.sshsig of OpenSSH
	 */
	public static function signedData(string $message): string
	{
		return 'SSHSIG' . self::sshString(self::Namespace) . self::sshString('') . self::sshString('sha512')
			. self::sshString(hash('sha512', $message, true));
	}


	/** Cookie from the output of `ssh-keygen -Y sign -n nette-debug` (armored SSHSIG) */
	public static function create(int $expiry, string $host, string $armoredSignature): string
	{
		$blob = base64_decode((string) preg_replace('~-----[A-Z ]+-----|\s~', '', $armoredSignature), true);
		$sshsig = $blob !== false && str_starts_with($blob, 'SSHSIG') ? self::readSshStrings(substr($blob, 10), 5) : null;
		$signature = $sshsig !== null && $sshsig[1] === self::Namespace && $sshsig[3] === 'sha512'
			? self::readSshStrings($sshsig[4], 2)
			: null;
		if ($signature === null || $signature[0] !== 'ssh-ed25519' || strlen($signature[1]) !== SODIUM_CRYPTO_SIGN_BYTES) {
			throw new \InvalidArgumentException('Expected an ssh-ed25519 signature of ssh-keygen -Y sign -n ' . self::Namespace . '.');
		}
		return $expiry . '.' . self::normalizeHost($host) . '.' . self::base64Url($signature[1]);
	}


	/**
	 * @param string[] $developers lines of APP_DEVELOPERS ("<slug>@ssh-ed25519 <base64>"), other lines
	 *                             are ignored
	 */
	public static function verify(mixed $token, array $developers, string $host, ?int $now = null): bool
	{
		if (
			!is_string($token)
			|| !preg_match('~^([0-9]{1,12})\.([a-z0-9.-]{1,253}|\*)\.([A-Za-z0-9_-]{86})$~D', $token, $m)
		) {
			return false;
		}
		[, $expiry, $tokenHost, $signature] = $m;
		$now ??= time();
		if ((int) $expiry <= $now || (int) $expiry > $now + self::MaxLifetime) {
			return false;
		}
		if ($tokenHost !== self::AnyHost && $tokenHost !== self::normalizeHost($host)) {
			return false;
		}

		$encoded = $signature;
		$signature = (string) base64_decode(strtr($signature, '-_', '+/'));
		if (self::base64Url($signature) !== $encoded) {
			// only the canonical encoding - the unused bits of the last character would give the same signature
			return false;
		}
		$signedData = self::signedData(self::message((int) $expiry, $tokenHost));
		foreach ($developers as $developer) {
			$publicKey = is_string($developer) ? self::publicKey($developer) : null;
			if ($publicKey !== null && sodium_crypto_sign_verify_detached($signature, $signedData, $publicKey)) {
				return true;
			}
		}
		return false;
	}


	/** Host without the port and the trailing dot, lowercase; "*" stays */
	public static function normalizeHost(string $host): string
	{
		return rtrim(strtolower((string) preg_replace('~:[0-9]+$~', '', $host)), '.');
	}


	/** Raw Ed25519 key from "<slug>@ssh-ed25519 <base64> [comment]", null for anything else */
	private static function publicKey(string $developer): ?string
	{
		if (!preg_match('~@ssh-ed25519 ([A-Za-z0-9+/]+=*)~', $developer, $m)) {
			return null;
		}
		$key = self::readSshStrings((string) base64_decode($m[1], true), 2);
		return $key !== null && $key[0] === 'ssh-ed25519' && strlen($key[1]) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
			? $key[1]
			: null;
	}


	/** @return list<string>|null the first $count strings of the SSH wire format, null when malformed */
	private static function readSshStrings(string $data, int $count): ?array
	{
		$strings = [];
		$offset = 0;
		for ($i = 0; $i < $count; $i++) {
			if (strlen($data) < $offset + 4) {
				return null;
			}
			$length = unpack('N', $data, $offset)[1];
			if (strlen($data) < $offset + 4 + $length) {
				return null;
			}
			$strings[] = substr($data, $offset + 4, $length);
			$offset += 4 + $length;
		}
		return $strings;
	}


	private static function sshString(string $value): string
	{
		return pack('N', strlen($value)) . $value;
	}


	private static function base64Url(string $data): string
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}
}
