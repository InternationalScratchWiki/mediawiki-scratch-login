<?php

use MediaWiki\User\UserFactory;
use MediaWiki\Logger\LoggerFactory;
use Wikimedia\Timestamp\ConvertibleTimestamp;

define('USER_API_LINK', 'https://api.scratch.mit.edu/users/%s/');

function getAuthenticator() {
	global $wgScratchLoginAuthenticator;
	switch ($wgScratchLoginAuthenticator) {
		case 'cloud': {
				return ScratchLogin\Authenticator\CloudVariableAuthenticator::class;
			}
		default: {
				return ScratchLogin\Authenticator\ProjectCommentAuthenticator::class;
			}
	}
}

function getScratchUserRegisteredAt($username) {
	$apiText = file_get_contents(sprintf(
		USER_API_LINK,
		$username
	));

	//fail loudly if the API call fails
	if (!isset($http_response_header)) {
		throw new Exception('API call failed');
	}

	//this shouldn't happen, but since this is a security-sensitive component we need to be ultra-defensive
	if (!strstr($http_response_header[0], '200 OK')) {
		throw new Exception('User does not exist');
	}

	$info = json_decode($apiText, true);

	$registeredAt = $info['history']['joined'];
	return new ConvertibleTimestamp($registeredAt);
}

class ScratchSpecialPage extends SpecialPage {
	private UserFactory $userFactory;

	function __construct(string $title, UserFactory $userFactory) {
		parent::__construct($title);
		$this->userFactory = $userFactory;
	}


	function execute($par) {
		$request = $this->getRequest();
		$out = $this->getOutput();
		$out->disallowUserJs();
		$this->setHeaders();
		$this->checkReadOnly();

		if ($par === 'reset') {
			$this->resetCode($out, $request);
		} else if ($request->wasPosted()) {
			$this->onPost($out, $request);
		} else {
			$this->showForm($out, $request);
		}
	}

	// show an error followed by the login form again
	function showError($error, $out, $request) {
		$out->addHTML(Html::rawElement('p', ['class' => 'error'], $error));
		$this->showForm($out, $request);
	}

	// $instructions: message key giving instructions for this page
	// $action: message key for button value
	function verifForm($out, $request, $instructions, $action) {
		$authenticator = getAuthenticator();

		// this all takes place in a form
		$out->addHTML(Html::openElement(
			'form',
			['method' => 'POST']
		));

		$session = $request->getSession();

		$out->addHTML($authenticator::getInstructions(
			$instructions,
			$session,
			$this
		)->inContentLanguage()->parseAsBlock());

		// show the submit button
		$out->addHTML(Html::element(
			'input',
			[
				'type' => 'submit',
				'id' => 'mw-scratchlogin-form-submit',
				'value' => wfMessage($action)->inContentLanguage()->plain()
			]
		));

		//close the form
		$out->addHTML(Html::closeElement('form'));
	}

	function verifSucceeded($out, $request) {
		$logger = LoggerFactory::getInstance('SpecialScratchLogin');
		$session = $request->getSession();
		$authenticator = getAuthenticator();
		$username = $authenticator::getAssociatedUsername($session, $this);

		if ($username === null) {
			$this->showError(
				$authenticator::getMissingMsg($this)
					->inContentLanguage()->parse(),
				$out,
				$request
			);
			return null;
		}

		// now attempt to retrieve the MediaWiki user
		// associated with whoever commented the verification code
		$user = $this->userFactory->newFromName($username);

		// ...if that user does not exist, then show an error
		// that this account does not exist on the wiki
		if (!$user->isRegistered()) {
			$this->showError(
				wfMessage('scratchlogin-unregistered', $username)
					->inContentLanguage()->parse(),
				$out,
				$request
			);
			return null;
		}

		try {
			$wikiUserTimestamp = new ConvertibleTimestamp($user->getRegistration());
			$scratchUserTimestamp = getScratchUserRegisteredAt($username);
			$diff = $scratchUserTimestamp->diff($wikiUserTimestamp);
			if ($diff->invert) {
				// Scratch user registered after wiki user.
				// To prevent disaster, make it error.
				$this->showError(
					wfMessage('scratchlogin-account-age-error', $username)
						->inContentLanguage()->parse(),
					$out,
					$request
				);
				return null;
			}
		} catch (Exception $e) {
			//in the event of any failure, do NOT allow the login attempt to continue

			$this->showError(wfMessage('scratchlogin-api-failure')->inContentLanguage()->parse(), $out, $request);
			$logger->error('Error while checking registration time: {exc}', ['exc' => $e]);

			return null;
		}

		// clear the verification code in the session so that they have to
		// use a different code to login as a different user
		$authenticator::clearAuthCode($session);
		return $user;
	}

	// reset the code associated with the current user's session
	function doCodeReset($out, $request, $returnto) {
		$session = $request->getSession();
		$authenticator = getAuthenticator();
		$authenticator::clearAuthCode($session);
		$out->addWikiMsg('scratchlogin-code-reset', $returnto);
	}
}
