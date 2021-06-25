<?php

namespace ScratchLogin\Authenticator;

use MediaWiki\Session\Session;
use MediaWiki\Logger\LoggerFactory;
use MessageLocalizer;
use Message;
use Html;
use Exception;

class CloudVariableAuthenticator extends Authenticator {
	private const CLOUD_API_URL = 'https://clouddata.scratch.mit.edu/logs?projectid=%s&limit=40&offset=0&cache=%s';
	private const PROJECT_LINK = 'https://scratch.mit.edu/projects/%s/';

	protected static function generateRandomAuthCode(): string {
		$a = random_int(1e18, PHP_INT_MAX);
		$b = random_int(1e18, PHP_INT_MAX);
		return "$a$b";
	}

	public static function getId(): string {
		return 'cloud';
	}

	public static function getAssociatedUsername(Session &$session, MessageLocalizer &$localizer): ?string {
		$logger = LoggerFactory::getInstance('CloudVariableAuthenticator');
		$varName = '☁ ' . $localizer->msg('scratchlogin-cloud-var-name')->plain();
		$authCode = self::getOrCreateAuthCode($session);
		try {
			$logs = self::getCloudVariableLogs($localizer);
		} catch (Exception $e) {
			$logger->warning('Could not get cloud variables for authentication: {exception}', array(
				'exception' => $e
			));
			return null;
		}
		foreach ($logs as &$log) {
			if ($log['verb'] === 'set_var' && $log['name'] === $varName && $log['value'] === $authCode) {
				$username = $log['user'];
				if (self::isSafeUsername($username)) {
					return $username;
				}
			}
		}
		return null;
	}

	private static function getCloudVariableLogs(MessageLocalizer &$localizer): array {
		$url = sprintf(
			self::CLOUD_API_URL,
			$localizer->msg('scratchlogin-cloud-project-id')->plain(),
			time()
		);
		return self::getJson($url);
	}

	public static function getInstructions(string $type, Session &$session, MessageLocalizer &$localizer): Message {
		$link = Html::openElement('a', [
			'href' => sprintf(self::PROJECT_LINK, $localizer->msg('scratchlogin-cloud-project-id')->plain()),
			'target' => '_blank'
		]);
		$endTag = Html::closeElement('a');
		return $localizer->msg("scratch$type-cloud-instructions")->rawParams(
			$link,
			$endTag,
			self::getOrCreateAuthCode($session)
		);
	}

	public static function getMissingMsg(MessageLocalizer &$localizer): Message {
		return $localizer->msg('scratchlogin-cloud-not-found');
	}
}
