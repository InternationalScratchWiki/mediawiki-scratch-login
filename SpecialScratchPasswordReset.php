<?php
require_once __DIR__ . '/ScratchLogin.common.php';

use MediaWiki\Auth\TemporaryPasswordAuthenticationRequest;
use MediaWiki\Auth\AuthManager;
use MediaWiki\User\UserFactory;

class SpecialScratchPasswordReset extends ScratchSpecialPage {
	private AuthManager $authManager;

	function __construct(UserFactory $userFactory, AuthManager $authManager) {
		parent::__construct('ScratchPasswordReset', $userFactory);
		$this->authManager = $authManager;
	}

	function getGroupName() {
		return 'users';
	}

	function showForm($out, $request) {
		// show the verification form with pwreset instructions and "Verify" button
		$this->verifForm(
			$out,
			$request,
			'passwordreset',
			'scratchpasswordreset-verify'
		);
	}

	function onPost($out, $request) {
		// this shows errors if verif failed
		$user = $this->verifSucceeded($out, $request);
		if ($user === null) return;
		// do the password reset, all has been checked
		// create a new auth request to set a temporary password
		$req = TemporaryPasswordAuthenticationRequest::newRandom();
		// disable sending password by email, which is the default for valid passwords
		$req->mailpassword = false;
		// this needs to be set for changeAuthenticationData
		$req->username = $user->getName();
		// use the global AuthManager to submit the auth request
		$this->authManager->changeAuthenticationData($req);
		// display the password and pass the username to log in with
		$out->addWikiMsg('scratchpasswordreset-success', $req->password, $user->getName());
	}

	// reset the code associated with the current user's session
	function resetCode($out, $request) {
		$this->doCodeReset($out, $request, 'Special:ScratchPasswordReset');
	}
}
