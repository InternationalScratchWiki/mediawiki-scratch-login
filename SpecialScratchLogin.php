<?php
function sessionVerificationCode(&$session) {
	if ($session->exists('vercode')) {
		return $session->get('vercode');
	} else {
		$session->persist();
		$session->set('vercode', sha1(rand()));
		$session->save();
		return $session->get('vercode');
	}
}

function getComments($project_id) {
	return json_decode(file_get_contents('https://api.scratch.mit.edu/users/ModShare/projects/10135908/comments?offset=0&limit=20'));
}

function commenter($project_id, $req_comment) {
	$comments = getComments($project_id);
	
	$matching_comments = array_filter($comments, function(&$comment) use($req_comment) { return stristr((string)$comment->content, (string)$req_comment); });
	return empty($matching_comments) ?
		null :
		(string)$matching_comments[0]->author->username;
}

class SpecialScratchLogin extends SpecialPage {
	function __construct() {
		parent::__construct('ScratchLogin');
	}
	
	function execute($par) {
		$request = $this->getRequest();
		$out = $this->getOutput();
		$this->setHeaders();
		
		if ($request->wasPosted()) {
			self::onPost( $par, $out, $request );
		} else {
			self::loginForm($par, $out, $request);
		}
	}
	
	static private function loginForm($par, $out, $request) {
		$out->addHTML(Html::openElement(
				'form',
				[ 'method' => 'POST' ]
		));
		
		$out->addHTML('<p>You need to enter the following verification code on the <a href="https://scratch.mit.edu/projects/10135908/" target="_blank">user verification project</a>: <br /><b>' . sessionVerificationCode($request->getSession()) . '</b></p>');
				
		$out->addHTML(Html::rawElement(
			'input',
			[
				'type' => 'submit',
				'id' => 'mw-scratchlogin-form-submit',
				'value' => 'Log in'
			]
		));
		
		$out->addHTML(Html::closeElement( 'form' ));
	}
	
	private static function showError($error, $par, $out, $request) {
		$out->addHTML('<p>' . $error . '</p>');
		self::loginForm($par, $out, $request);
	}
	
	private static function onPost( $par, $out, $request ) {
		global $wgSecureLogin;
		
		$username = commenter('', sessionVerificationCode($request->getSession()));
		
		if ($username == null) {
			self::showError('You do not appear to have comented the code on the project.', $par, $out, $request);
			return;
		}
						
		$user = User::newFromName($username);
		if ($user->getId() == 0) {
			self::showError('The user <b>' . $username . '</b> is not registered on this wiki', $par, $out, $request);
			return;
		}
		
		$request->getSession()->clear('vercode');
		$request->getSession()->setUser($user);
		$request->getSession()->save();
		
		$out->addHTML('<p>Success! You are now logged in as <b>' . $username . '</b></p>');
	}
}
