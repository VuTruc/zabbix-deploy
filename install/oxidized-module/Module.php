<?php

namespace Modules\Oxidized;

use Zabbix\Core\CModule,
    APP,
    CMenu,
    CMenuItem,
    CUrl;

class Module extends CModule {

    public function init(): void {
        APP::Component()->get('menu.main')
            ->add(
                (new CMenuItem(_('Oxidized')))
                    ->setId('oxidized')
                    ->setSubMenu(new CMenu([
                        (new CMenuItem(_('Nodes')))
                            ->setAction('oxidized.nodes'),
                        (new CMenuItem(_('Open Oxidized Web')))
                            ->setUrl(new CUrl('/oxidized/'))
                            ->setTarget('_blank')
                    ]))
            );
    }
}
