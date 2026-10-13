<?php

namespace Modules\Oxidized\Actions;

use CController,
    CControllerResponseRedirect;

class OxidizedRedirect extends CController {

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return true;
    }

    protected function doAction(): void {
        $this->setResponse(
            new CControllerResponseRedirect('/oxidized/')
        );
    }
}
