<?php

use Wikimedia\Timestamp\ConvertibleTimestamp;

define('SCRATCH_COMMENT_API_URL', 'https://api.scratch.mit.edu/users/%s/projects/%s/comments?offset=0&limit=20');
define('PROJECT_LINK', 'https://scratch.mit.edu/projects/%s/');
define('USER_API_LINK', 'https://api.scratch.mit.edu/users/%s/');

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
		if (preg_match('/^_+|_+$|__+/', $comment['author']['username'])) return false;
		return stristr($comment['content'], $req_comment);
	});
	if (empty($matching_comments)) {
		return null;
	}
	return array_values($matching_comments)[0]['author']['username'];
}

function getScratchUserRegisteredAt($username) {
	$info = json_decode(file_get_contents(sprintf(
		USER_API_LINK, $username
	)), true);
	$registeredAt = $info['history']['joined'];
	return new ConvertibleTimestamp($registeredAt);
}

class ScratchSpecialPage extends SpecialPage {

	function execute($par) {
		$request = $this->getRequest();
		$out = $this->getOutput();
		$out->disallowUserJs();
		$this->setHeaders();

		if ($par == 'reset') {
			$this->resetCode( $out, $request );
		} else if ($request->wasPosted()) {
			$this->onPost( $out, $request );
		} else {
			$this->showForm( $out, $request );
		}
	}

	// show an error followed by the login form again
	function showError($error, $out, $request) {
		$out->addHTML(Html::rawElement('p', [ 'class' => 'error' ], $error));
		$this->showForm($out, $request);
	}

	// $instructions: message key giving instructions for this page
	// $action: message key for button value
	function verifForm($out, $request, $instructions, $action) {
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

		$session = $request->getSession();

		// show the instructions to comment the verification code
		// on the project (using the link we generated above)
		$out->addHTML(wfMessage($instructions)->rawParams(
			$link, Html::closeElement( 'a' ),
			sessionVerificationCode($session)
		)->inContentLanguage()->parseAsBlock());

		// show the submit button
		$out->addHTML(Html::rawElement(
			'input',
			[
				'type' => 'submit',
				'id' => 'mw-scratchlogin-form-submit',
				'value' => wfMessage($action)->inContentLanguage()->plain()
			]
		));

		//close the form
		$out->addHTML(Html::closeElement( 'form' ));
	}

	function verifSucceeded($out, $request) {
		$session = $request->getSession();
		// see the first person to comment the verification code
		$username = topVerifCommenter(sessionVerificationCode($session));

		// if nobody commented the verification code, show an error
		if ($username == null) {
			$this->showError(
				wfMessage('scratchlogin-uncommented')
				->inContentLanguage()->plain(),
				$out, $request
			);
			return null;
		}

		// now attempt to retrieve the MediaWiki user
		// associated with whoever commented the verification code
		$user = User::newFromName($username);

		// ...if that user does not exist, then show an error
		// that this account does not exist on the wiki
		if ($user->getId() == 0) {
			$this->showError(
				wfMessage('scratchlogin-unregistered', $username)
				->inContentLanguage()->parse(),
				$out, $request
			);
			return null;
		}
		
		$wikiUserTimestamp = new ConvertibleTimestamp($user->getRegistration());
		$scratchUserTimestamp = getScratchUserRegisteredAt($username);
		$diff = $scratchUserTimestamp->diff($wikiUserTimestamp);
		if ($diff->invert) {
			// Scratch user registered after wiki user.
			// To prevent disaster, make it error.
			$this->showError(
				wfMessage('scratchlogin-account-age-error', $username)
				->inContentLanguage()->parse(),
				$out, $request
			);
			return null;
		}

		// clear the verification code in the session so that they have to
		// use a different code to login as a different user
		$request->getSession()->clear('vercode');
		return $user;
	}

	// reset the code associated with the current user's session
	function doCodeReset($out, $request, $returnto) {
		$session = $request->getSession();
		generateNewCodeForSession($session);
		$out->addWikiMsg('scratchlogin-code-reset', $returnto);
	}
}
