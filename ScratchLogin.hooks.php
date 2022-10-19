<?php

use MediaWiki\Hook\BeforePageDisplayHook;

class ScratchLoginHooks implements BeforePageDisplayHook {
	// add a link to login with Scratch after the login page
	// hook name that calls this is BeforePageDisplay
	public function onBeforePageDisplay($out, $skin) : void {
		$title = $out->getContext()->getTitle();
		// this hook is called on all pages,
		// so check that we're on the right Special page
		if ($title->isSpecial('Userlogin')) {
			// link to Special:ScratchLogin on Special:UserLogin
			$out->addWikiMsg('scratchlogin-userlogin-link');
		}
		if ($title->isSpecial('PasswordReset')) {
			// link to Special:ScratchPasswordReset on Special:PasswordReset
			$out->addWikiMsg('scratchpasswordreset-passwordreset-link');
		}
	}
}
