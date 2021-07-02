<?php

namespace ScratchLogin\Authenticator;

use MediaWiki\Session\Session;
use MediaWiki\Logger\LoggerFactory;
use MessageLocalizer;
use Message;
use Html;
use Exception;

class ProjectCommentAuthenticator extends Authenticator {
	private const COMMENT_API_URL = 'https://api.scratch.mit.edu/users/%s/projects/%s/comments?offset=0&limit=20&cache=%s';
	private const PROJECT_LINK = 'https://scratch.mit.edu/projects/%s/';

	protected static function generateRandomAuthCode(): string {
		return strtr(hash('sha256', random_bytes(16)), '0123456789', 'ABCDEFGHIJ');
	}

	public static function getId(): string {
		return 'project';
	}

	public static function getAssociatedUsername(Session &$session, MessageLocalizer &$localizer): ?string {
		$logger = LoggerFactory::getInstance('ProjectCommentAuthenticator');
		$authCode = self::getOrCreateAuthCode($session);
		try {
			$comments = self::getComments($localizer);
		} catch (Exception $e) {
			$logger->warning('Could not get project comments for authentication: {exception}', array(
				'exception' => $e
			));
			return null;
		}
		foreach ($comments as &$comment) {
			if (strpos($comment['content'], $authCode) !== false) {
				$username = $comment['author']['username'];
				if (self::isSafeUsername($username)) {
					return $username;
				}
			}
		}
		return null;
	}

	private static function getComments(MessageLocalizer &$localizer): array {
		$url = sprintf(
			self::COMMENT_API_URL,
			$localizer->msg('scratchlogin-project-author')->plain(),
			$localizer->msg('scratchlogin-project-id')->plain(),
			time()
		);
		return self::getJson($url);
	}

	public static function getInstructions(string $type, Session &$session, MessageLocalizer &$localizer): Message {
		$link = Html::openElement('a', [
			'href' => sprintf(self::PROJECT_LINK, $localizer->msg('scratchlogin-project-id')->plain()),
			'target' => '_blank'
		]);
		$endTag = Html::closeElement('a');
		return $localizer->msg("scratch$type-instructions")->rawParams(
			$link,
			$endTag,
			self::getOrCreateAuthCode($session)
		);
	}

	public static function getMissingMsg(MessageLocalizer &$localizer): Message {
		return $localizer->msg('scratchlogin-uncommented');
	}
}
