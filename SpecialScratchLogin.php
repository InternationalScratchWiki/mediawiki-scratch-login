<?php
define('API_URL', 'https://api.scratch.mit.edu/users/%s/projects/%s/comments?offset=0&limit=20');
define('PROJECT_LINK', 'https://scratch.mit.edu/projects/%s/');

function sessionVerificationCode(&$session) {
	if ($session->exists('vercode')) {
		return $session->get('vercode');
	} else {
		$session->persist();
		$session->set('vercode', hash('sha256', random_bytes(16)));
		$session->save();
		return $session->get('vercode');
	}
}

function getComments() {
	return json_decode(file_get_contents(sprintf(
		API_URL, wfMessage('scratchlogin-project-author')->text(),
		wfMessage('scratchlogin-project-id')->text()
	)), TRUE);
}

function commenter($req_comment) {
	$comments = getComments();
	
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
		
		if ($request->wasPosted()) {
			self::onPost( $par, $out, $request );
		} else {
			self::loginForm( $par, $out, $request );
		}
	}
	
	static private function loginForm($par, $out, $request) {
		$out->addHTML(Html::openElement(
				'form',
				[ 'method' => 'POST' ]
		));
		
		$link = Html::openElement('a', [
			'href' => sprintf(PROJECT_LINK, wfMessage('scratchlogin-project-id')->text()),
			'target' => '_blank'
		]);
		
		$out->addHTML(wfMessage(
			'scratchlogin-instructions',
			$link, Html::closeElement( 'a' ),
			sessionVerificationCode($request->getSession())
		)->inContentLanguage()->parseAsBlock());
				
		$out->addHTML(Html::rawElement(
			'input',
			[
				'type' => 'submit',
				'id' => 'mw-scratchlogin-form-submit',
				'value' => wfMessage('login')->inContentLangauge()->plain()
			]
		));
		
		$out->addHTML(Html::closeElement( 'form' ));
	}
	
	private static function showError($error, $par, $out, $request) {
		$out->addHTML(Html::rawElement('p', [ 'class' => 'error' ], $error));
		self::loginForm($par, $out, $request);
	}
	
	private static function onPost( $par, $out, $request ) {
		global $wgSecureLogin;
		
		$username = commenter(sessionVerificationCode($request->getSession()));
		
		if ($username == null) {
			self::showError(
				wfMessage('scratchlogin-uncommented')
				->inContentLangauge()->plain(),
				$par, $out, $request
			);
			return;
		}
						
		$user = User::newFromName($username);
		if ($user->getId() == 0) {
			self::showError(
				wfMessage('scratchlogin-unregistered', $username)
				->inContentLangauge()->parse(),
				$par, $out, $request
			);
			return;
		}
		
		$request->getSession()->clear('vercode');
		$request->getSession()->setUser($user);
		$request->getSession()->save();
		
		$out->addWikiMsg('scratchlogin-success', $username);
	}
}
