<?php

class ScratchLoginHooks {
	// add a link to login with Scratch after the login page
	// hook name that calls this is BeforePageDisplay
	static public function insertScratchLoginLink( &$out, &$skin ) {
		$title = $out->getContext()->getTitle();
		// this hook is called on all pages,
		// so check that we're on the right Special page
		if ($title->isSpecial( 'Userlogin' )) {
			// link to Special:ScratchLogin on Special:UserLogin
			$out->addWikiMsg('scratchlogin-userlogin-link');
			return true;
		}
		if ($title->isSpecial( 'PasswordReset' )) {
			// link to Special:ScratchPasswordReset on Special:PasswordReset
			$out->addWikimsg('scratchpasswordreset-passwordreset-link');
			return true;
		}
		return true;
	}
}
