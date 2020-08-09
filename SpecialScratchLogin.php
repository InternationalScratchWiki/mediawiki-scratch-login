<?php
define('SCRATCH_COMMENT_API_URL', 'https://api.scratch.mit.edu/users/%s/projects/%s/comments?offset=0&limit=20');
define('PROJECT_LINK', 'https://scratch.mit.edu/projects/%s/');

function randomVerificationCode() {
	// translate 0->A, 1->B, etc to bypass Scratch phone number censor
	return strtr(hash('sha256', random_bytes(16)), '0123456789', 'ABCDEFGHIJ');
}

function generateNewCodeForSession(&$session) {
	$session->persist();
	$session->set('vercode', randomVerificationCode());
	$session->save();
}

function sessionVerificationCode(&$session) {
	if (!$session->exists('vercode')) {
		generateNewCodeForSession($session);
	}
	return $session->get('vercode');
}

function commentsForProject($author, $project_id) {
	return json_decode(file_get_contents(sprintf(
		SCRATCH_COMMENT_API_URL, $author, $project_id
	)), true);
}

function verifComments() {
	return commentsForProject(
		wfMessage('scratchlogin-project-author')->text(),
		wfMessage('scratchlogin-project-id')->text()
	);
}

function topVerifCommenter($req_comment) {
	$comments = verifComments();

	$matching_comments = array_filter($comments, function(&$comment) use($req_comment) {
		return stristr($comment['content'], $req_comment);
	});
	if (empty($matching_comments)) {
		return null;
	}
	return $matching_comments[0]['author']['username'];
}

class SpecialScratchLogin extends SpecialPage {
	function __construct() {
		parent::__construct('ScratchLogin');
	}

	function getGroupName() {
		return 'login';
	}

	function execute($par) {
		$request = $this->getRequest();
		$out = $this->getOutput();
		$this->setHeaders();

		if ($par == 'reset') {
			self::resetCode( $par, $out, $request );
		} else if ($request->wasPosted()) {
			self::onPost( $par, $out, $request );
		} else {
			self::loginForm( $par, $out, $request );
		}
	}

	// add a link to login with Scratch after the login page
	// hook name that calls this is BeforePageDisplay
	static public function insertScratchLoginLink( &$out, &$skin ) {
		$title = $out->getContext()->getTitle();
		// this hook is called on all pages,
		// so check that we're on the right Special page
		if (!$title->isSpecial( 'Userlogin' )) {
			return true;
		}
		$out->addWikiMsg('scratchlogin-userlogin-link');
		return true;
	}

	static private function loginForm($par, $out, $request) {
		// this all takes place in a form
		$out->addHTML(Html::openElement(
				'form',
				[ 'method' => 'POST' ]
		));

		// create a link to the user verification project
		$link = Html::openElement('a', [
			'href' => sprintf(PROJECT_LINK, wfMessage('scratchlogin-project-id')->text()),
			'target' => '_blank'
		]);

		// show the instructions to comment the verification code
		// on the project (using the link we generated above)
		$out->addHTML(wfMessage('scratchlogin-instructions')->rawParams(
			$link, Html::closeElement( 'a' ),
			sessionVerificationCode($request->getSession())
		)->inContentLanguage()->parseAsBlock());

		// show the submit button
		$out->addHTML(Html::rawElement(
			'input',
			[
				'type' => 'submit',
				'id' => 'mw-scratchlogin-form-submit',
				'value' => wfMessage('login')->inContentLanguage()->plain()
			]
		));

		//close the form
		$out->addHTML(Html::closeElement( 'form' ));
	}

	// show an error followed by the login form again
	private static function showError($error, $par, $out, $request) {
		$out->addHTML(Html::rawElement('p', [ 'class' => 'error' ], $error));
		self::loginForm($par, $out, $request);
	}

	// handle someone hitting the login button
	private static function onPost( $par, $out, $request ) {
		// see the first person to comment the verification code
		$username = topVerifCommenter(sessionVerificationCode($request->getSession()));

		// if nobody commented the verification code,
		// then the login attempt failed, so show an error
		if ($username == null) {
			self::showError(
				wfMessage('scratchlogin-uncommented')
				->inContentLanguage()->plain(),
				$par, $out, $request
			);
			return;
		}

		// now attempt to retrieve the MediaWiki user
		// associated with whoever commented the verification code
		$user = User::newFromName($username);

		// ...if that user does not exist, then show an error
		// that this account does not exist on the wiki
		if ($user->getId() == 0) {
			self::showError(
				wfMessage('scratchlogin-unregistered', $username)
				->inContentLanguage()->parse(),
				$par, $out, $request
			);
			return;
		}

		// now that we have passed all the other hurdles, log in the user
		// clear the verification code in the session so that they have to
		// use a different code to login as a different user
		$request->getSession()->clear('vercode');
		// set the logged in user to the user found by that name
		$request->getSession()->setUser($user);
		$request->getSession()->save();

		// and, finally, display the result
		$out->addWikiMsg('scratchlogin-success', $username);
	}

	// reset the code associated with the current user's session
	private static function resetCode( $par, $out, $request) {
		generateNewCodeForSession($request->getSession());
		$out->addWikiMsg('scratchlogin-code-reset');
	}
}
