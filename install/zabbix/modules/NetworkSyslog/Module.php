<?php

namespace Modules\NetworkSyslog;

use Zabbix\Core\CModule,
    APP,
    CMenuItem;

class Module extends CModule {

    public function init(): void {
        APP::Component()->get('menu.main')
            ->add(
                (new CMenuItem(_('Network Syslog')))
                    ->setId('network-syslog')
                    ->setAction('network.syslog')
            );
    }
}
