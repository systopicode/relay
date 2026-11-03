<?php

/**
 * @var \Systopic\Relay\Panels\Root\Admin\Tools\Debug\Panel $this
 */

switch ($this->action) {
	case 'refreshList':
		$this->updateViews(['main']);
		break;
}
