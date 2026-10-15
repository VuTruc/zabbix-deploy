<?php

namespace Modules\Oxidized;

use Zabbix\Core\CModule,
    APP,
    CMenu,
    CMenuItem;

class Module extends CModule {

    public function init(): void {
        APP::Component()->get('menu.main')
            ->add(
                (new CMenuItem(_('Oxidized')))
                    ->setId('oxidized')
                    ->setSubMenu(new CMenu([
                        (new CMenuItem(_('Dashboard')))
                            ->setAction('oxidized.dashboard'),
                        // Configs and versions are viewed inside Zabbix
                        // (Nodes > Config), so Oxidized Web has no menu entry.
                        (new CMenuItem(_('Nodes')))
                            ->setAction('oxidized.nodes')
                            ->setAliases(['oxidized.config'])
                    ]))
            );
    }
}
