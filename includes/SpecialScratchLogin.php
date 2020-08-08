<?php
class SpecialScratchLogin extends SpecialPage {
	public function __construct() {
		parent::__construct('ScratchLogin');
	}
	
	public function execute($par) {		
		$this->setHeaders();
		$out = $this->getOutput();
		
		$out->addHTML('hi');
	}
}