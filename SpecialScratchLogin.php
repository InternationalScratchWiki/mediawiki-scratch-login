<?php
require_once __DIR__ . '/ScratchLogin.common.php';

use MediaWiki\User\UserFactory;

class SpecialScratchLogin extends ScratchSpecialPage {
	function __construct(UserFactory $userFactory) {
		parent::__construct('ScratchLogin', $userFactory);
	}

	function getGroupName() {
		return 'login';
	}

	function showForm($out, $request) {
		// show the verification form with login instructions and "Log in" button
		$this->verifForm($out, $request, 'login', 'login');
	}

	// handle someone hitting the login button
	function onPost($out, $request) {
		// this handles all of the error message showing
		// and returns null if failed
		$user = $this->verifSucceeded($out, $request);
		if ($user == null) return;
		// now that we have passed all the other hurdles, log in the user
		// set the logged in user to the user found by that name
		$request->getSession()->setUser($user);
		$request->getSession()->save();

		// and, finally, display the result
		$out->addWikiMsg('scratchlogin-success', $user->getName());
	}

	// reset the code associated with the current user's session
	function resetCode($out, $request) {
		$this->doCodeReset($out, $request, 'Special:ScratchLogin');
	}
}
