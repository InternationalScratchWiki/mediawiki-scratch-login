<?php

namespace ScratchLogin\Authenticator;

use MediaWiki\Session\Session;
use MessageLocalizer;
use Message;
use Exception;

abstract class Authenticator {
	abstract protected static function generateRandomAuthCode(): string;

	abstract public static function getId(): string;

	abstract public static function getAssociatedUsername(Session &$session, MessageLocalizer &$localizer): ?string;

	abstract public static function getInstructions(string $instructions, Session &$session, MessageLocalizer &$localizer): Message;

	abstract public static function getMissingMsg(MessageLocalizer &$localizer): Message;

	private static function getSessionName(): string {
		return 'vercode-' . static::getId();
	}

	protected static function generateAndSetAuthCode(Session &$session): string {
		$code = static::generateRandomAuthCode();
		$session->persist();
		$session->set(self::getSessionName(), $code);
		$session->save();
		return $code;
	}

	protected static function getOrCreateAuthCode(Session &$session): string {
		$name = self::getSessionName();
		if ($session->exists($name)) {
			return $session->get($name);
		}
		return self::generateAndSetAuthCode($session);
	}

	public static function clearAuthCode(Session &$session): void {
		$session->clear(self::getSessionName());
	}

	protected static function isSafeUsername(string $username): bool {
		return !preg_match('/^_+|_+$|__+/', $username);
	}

	protected static function get(string $url): string {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$result = curl_exec($ch);
		if ($result === false) {
			$err = "Authenticator::get failed while querying $url: " . curl_error($ch);
			curl_close($ch);
			throw new Exception($err);
		}
		$statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		if ($statusCode !== 200) {
			curl_close($ch);
			throw new Exception("Authenticator::get failed while querying $url: status code $statusCode");
		}
		curl_close($ch);
		return $result;
	}

	protected static function getJson(string $url): array {
		return json_decode(self::get($url), true);
	}
}
