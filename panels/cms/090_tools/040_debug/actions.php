<?php

/**
 * @var \Systopic\Relay\Panels\Root\Cms\Tools\Debug\Panel $this
 */

switch ($this->action) {
	case 'refreshList':
		$this->updateViews(['main']);
		break;
}
