<?php

$this->addCssFile('modules/Tacacs/assets/tacacs.css');

require_once dirname(__DIR__).'/include/ApplyQueue.php';
require_once dirname(__DIR__).'/include/VendorProfiles.php';

(new CHtmlPage())
    ->setTitle(_('TACACS+ Dashboard'))
    ->addItem(\Modules\Tacacs\ApplyQueue::statusBox())
    ->addItem(
        (new CDiv([

            /* =========================
             * Overview - separate area
             * ========================= */
            (new CDiv([
                (new CTag('h2', true))
                    ->addClass('tacacs-section-title')
                    ->addItem(_('Overview')),

                (new CTag('div', true))
                    ->addClass('tacacs-stats')
                    ->addItem(
                        (new CLink(
                            new CTag('div', true, [
                                (new CTag('div', true))
                                    ->addClass('tacacs-stat-title')
                                    ->addItem(_('Users')),
                                (new CTag('div', true))
                                    ->addClass('tacacs-stat-value')
                                    ->addItem($data['stats']['users']),
                                (new CTag('div', true))
                                    ->addClass('tacacs-stat-link')
                                    ->addItem(_('View Users →'))
                            ]),
                            (new CUrl('zabbix.php'))
                                ->setArgument('action', 'tacacs.users')
                                ->getUrl()
                        ))
                            ->addClass('tacacs-stat')
                    )
                    ->addItem(
                        (new CLink(
                            new CTag('div', true, [
                                (new CTag('div', true))
                                    ->addClass('tacacs-stat-title')
                                    ->addItem(_('Devices')),
                                (new CTag('div', true))
                                    ->addClass('tacacs-stat-value')
                                    ->addItem($data['stats']['devices']),
                                (new CTag('div', true))
                                    ->addClass('tacacs-stat-link')
                                    ->addItem(_('View Devices →'))
                            ]),
                            (new CUrl('zabbix.php'))
                                ->setArgument('action', 'tacacs.devices')
                                ->getUrl()
                        ))
                            ->addClass('tacacs-stat')
                    )
                    ->addItem(
                        (new CLink(
                            new CTag('div', true, [
                                (new CTag('div', true))
                                    ->addClass('tacacs-stat-title')
                                    ->addItem(_('Policies')),
                                (new CTag('div', true))
                                    ->addClass('tacacs-stat-value')
                                    ->addItem($data['stats']['policies']),
                                (new CTag('div', true))
                                    ->addClass('tacacs-stat-link')
                                    ->addItem(_('View Policies →'))
                            ]),
                            (new CUrl('zabbix.php'))
                                ->setArgument('action', 'tacacs.policies')
                                ->getUrl()
                        ))
                            ->addClass('tacacs-stat')
                    )
                    ->addItem(
                        (new CLink(
                            new CTag('div', true, [
                                (new CTag('div', true))
                                    ->addClass('tacacs-stat-title')
                                    ->addItem(_('Rules')),
                                (new CTag('div', true))
                                    ->addClass('tacacs-stat-value')
                                    ->addItem($data['stats']['rules']),
                                (new CTag('div', true))
                                    ->addClass('tacacs-stat-link')
                                    ->addItem(_('View Rules →'))
                            ]),
                            (new CUrl('zabbix.php'))
                                ->setArgument('action', 'tacacs.policies')
                                ->getUrl()
                        ))
                            ->addClass('tacacs-stat')
                    )
                    ->addItem(
                        (new CLink(
                            new CTag('div', true, [
                                (new CTag('div', true))
                                    ->addClass('tacacs-stat-title')
                                    ->addItem(_('Assignments')),
                                (new CTag('div', true))
                                    ->addClass('tacacs-stat-value')
                                    ->addItem($data['stats']['assignments']),
                                (new CTag('div', true))
                                    ->addClass('tacacs-stat-link')
                                    ->addItem(_('View Assignments →'))
                            ]),
                            (new CUrl('zabbix.php'))
                                ->setArgument('action', 'tacacs.assignments')
                                ->getUrl()
                        ))
                            ->addClass('tacacs-stat')
                    )
            ]))
                ->addClass('tacacs-dashboard-section'),

            /* =========================
             * Users + Devices + Policies
             * + Policy Rules - one area
             * ========================= */
            (new CDiv([

                (new CTag('h2', true))
                    ->addClass('tacacs-section-title')
                    ->addItem(_('Users')),

                (function () use ($data) {
                    $table = new CTableInfo();

                    $table->setHeader([
                        _('Username'),
                        _('Description'),
                        _('Status'),
                        _('Created')
                    ]);

                    foreach ($data['users'] as $user) {
                        $table->addRow([
                            $user['username'],
                            $user['description'] ?? '',
                            $user['enabled']
                                ? _('Enabled')
                                : _('Disabled'),
                            $user['created_at']
                        ]);
                    }

                    return $table;
                })(),

                (new CTag('h2', true))
                    ->addClass('tacacs-section-title')
                    ->addItem(_('Devices')),

                (function () use ($data) {
                    $table = new CTableInfo();

                    $table->setHeader([
                        _('Name'),
                        _('Address'),
                        _('Vendor / Platform'),
                        _('Status')
                    ]);

                    foreach ($data['devices'] as $device) {
                        $table->addRow([
                            $device['name'],
                            $device['address'],
                            \Modules\Tacacs\VendorProfiles::displayName((string) $device['vendor']),
                            $device['enabled']
                                ? _('Enabled')
                                : _('Disabled')
                        ]);
                    }

                    return $table;
                })(),

                (new CTag('h2', true))
                    ->addClass('tacacs-section-title')
                    ->addItem(_('Policies')),

                (function () use ($data) {
                    $table = new CTableInfo();

                    $table->setHeader([
                        _('Policy'),
                        _('Description'),
                        _('Status')
                    ]);

                    foreach ($data['policies'] as $policy) {
                        $table->addRow([
                            $policy['name'],
                            $policy['description'] ?? '',
                            $policy['enabled']
                                ? _('Enabled')
                                : _('Disabled')
                        ]);
                    }

                    return $table;
                })(),

                (new CTag('h2', true))
                    ->addClass('tacacs-section-title')
                    ->addItem(_('Policy Rules')),

                (function () use ($data) {
                    $table = new CTableInfo();

                    $table->setHeader([
                        _('Policy'),
                        _('Command'),
                        _('Arguments'),
                        _('Action'),
                        _('Order')
                    ]);

                    foreach ($data['rules'] as $rule) {
                        $table->addRow([
                            $rule['policy_name'],
                            $rule['command'],
                            $rule['command_args'],
                            strtoupper($rule['action']),
                            $rule['rule_order']
                        ]);
                    }

                    return $table;
                })(),

                (new CTag('h2', true))
                    ->addClass('tacacs-section-title')
                    ->addItem(_('Current Assignments')),

                (function () use ($data) {
                    $table = new CTableInfo();

                    $table->setHeader([
                        _('User'),
                        _('Device'),
                        _('Policy'),
                        _('Created')
                    ]);

                    foreach ($data['assignments'] as $assignment) {
                        $table->addRow([
                            $assignment['username'],
                            $assignment['device_name'].' ('.$assignment['device_address'].')',
                            $assignment['policy_name'],
                            $assignment['created_at']
                        ]);
                    }

                    return $table;
                })()

            ]))
                ->addClass('tacacs-dashboard-section')

        ]))
            ->addClass('tacacs-dashboard')
    )
    ->show();
