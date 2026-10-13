<?php

namespace Modules\Tacacs;

use Zabbix\Core\CModule,
    APP,
    CMenu,
    CMenuItem;

class Module extends CModule {

    public function init(): void {
        APP::Component()->get('menu.main')
            ->add(
                (new CMenuItem(_('TACACS+')))
                    ->setId('tacacs')
                    ->setSubMenu(new CMenu([
                        (new CMenuItem(_('Dashboard')))
                            ->setAction('tacacs.dashboard'),


                        (new CMenuItem(_('Users')))
                            ->setAction('tacacs.users'),

                        (new CMenuItem(_('Devices')))
                            ->setAction('tacacs.devices'),

                        (new CMenuItem(_('Policies')))
                            ->setAction('tacacs.policies'),

                        (new CMenuItem(_('Assignments')))
                            ->setAction('tacacs.assignments'),
                        (new CMenuItem(_('Audit Logs')))
                            ->setAction('tacacs.auditlogs'),
                    ]))
            );
    }
}
