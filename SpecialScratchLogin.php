<?php
define('SCRATCH_COMMENT_API_URL', 'https://api.scratch.mit.edu/users/%s/projects/%s/comments?offset=0&limit=20');
define('PROJECT_LINK', 'https://scratch.mit.edu/projects/%s/');

function randomVerificationCode() {
	return sha1(rand());
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
	return json_decode(file_get_contents(sprintf(SCRATCH_COMMENT_API_URL, $author, $project_id)), TRUE);
}

function commentsForVerificationProject() {
	return commentsForProject(wfMessage('scratchlogin-project-author')->text(), wfMessage('scratchlogin-project-id')->text());
}

function firstPersonToLeaveCommentOnVerificationProject($req_comment) {
	$comments = commentsForVerificationProject();

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

	static private function loginForm($par, $out, $request) {
		//this all takes place in a form
		$out->addHTML(Html::openElement(
				'form',
				[ 'method' => 'POST' ]
		));

		//create a link to the user verification project
		$link = Html::openElement('a', [
			'href' => sprintf(PROJECT_LINK, wfMessage('scratchlogin-project-id')->text()),
			'target' => '_blank'
		]);

		//show the instructions to comment the verification code on the project (using the link we generated above)
		$out->addHTML(wfMessage('scratchlogin-instructions')->rawParams(
			$link, Html::closeElement( 'a' ),
			sessionVerificationCode($request->getSession())
		)->inContentLanguage()->parseAsBlock());

		//show the submit button
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
	
	//show an error followed by the login form again
	private static function showError($error, $par, $out, $request) {
		$out->addHTML(Html::rawElement('p', [ 'class' => 'error' ], $error));
		self::loginForm($par, $out, $request);
	}

	//handle someone hitting the login button
	private static function onPost( $par, $out, $request ) {
		//see the first person to comment the verification code 
		$username = firstPersonToLeaveCommentOnVerificationProject(sessionVerificationCode($request->getSession()));

		//if nobody commented the verification code, then the login attempt failed, so show an error
		if ($username == null) {
			self::showError(
				wfMessage('scratchlogin-uncommented')
				->inContentLanguage()->plain(),
				$par, $out, $request
			);
			return;
		}

		//now attempt to retrieve the MediaWiki user associated with whoever commented the verification code
		$user = User::newFromName($username);
		
		//...if that user does not exist, then show an error that this account does not exist on the wiki
		if ($user->getId() == 0) {
			self::showError(
				wfMessage('scratchlogin-unregistered', $username)
				->inContentLanguage()->parse(),
				$par, $out, $request
			);
			return;
		}

		//now that we have passed all the other hurdles, log in the user
		$request->getSession()->clear('vercode'); //clear out the verification code from the session so if the user tries to log in again, they'll need a different code
		$request->getSession()->setUser($user);
		$request->getSession()->save();

		//and, finally, display the result
		$out->addWikiMsg('scratchlogin-success', $username);
	}

	//reset the code associated with the current user's session
	private static function resetCode( $par, $out, $request) {
		generateNewCodeForSession($request->getSession());
		$out->addWikiMsg('scratchlogin-code-reset');
	}
}
