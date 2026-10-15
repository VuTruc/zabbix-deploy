<?php

namespace Modules\Tacacs;

/**
 * Manufacturer -> platform -> privilege level mapping.
 *
 * Shared by the frontend and the CLI config generator, so it must stay plain
 * data: no Zabbix functions such as the gettext wrapper here.
 *
 * A device stores its platform key (devices.vendor). Each level lists the
 * tac_plus service blocks returned to the device:
 *   'blocks' => [['service' => 'exec', 'attributes' => [
 *       ['name' => 'priv-lvl', 'value' => '15', 'quote' => false, 'optional' => false]
 *   ]]]
 * A platform with 'custom' accepts a device-side name (admin profile, role,
 * template user) typed in Assignments; '{value}' is replaced by it.
 *
 * command_authorization: the device sends each command to the server, so a
 * command Policy is enforced. Without it a Policy would have no effect.
 * requires_policy: the level is only meaningful with a command Policy
 * (Cisco Custom = priv-lvl 7 limited to the Policy's commands).
 * verified: false marks attribute sets taken from documentation only.
 *
 * Add a platform only with a documented attribute set.
 */
class VendorProfiles {

    public const DEFAULT_VENDOR = 'generic';
    public const DEFAULT_LEVEL = 'default';
    public const CUSTOM_LEVEL = 'custom';
    public const CUSTOM_PATTERN = '/^[A-Za-z0-9_.@-]{1,64}$/';

    public static function manufacturers(): array {
        return [
            'generic' => 'Generic',
            'cisco' => 'Cisco',
            'fortinet' => 'Fortinet',
            'paloalto' => 'Palo Alto Networks',
            'juniper' => 'Juniper'
        ];
    }

    public static function all(): array {
        $deviceDefault = [
            'label' => 'Device default',
            'description' => 'Không gửi thuộc tính phân quyền; thiết bị dùng cấu hình mặc định của nó.',
            'blocks' => []
        ];

        $ciscoLevels = [
            'readonly' => [
                'label' => 'Read-only (level 1)',
                'description' => 'priv-lvl 1: chỉ xem, không vào được enable.',
                'blocks' => [self::block('exec', 'priv-lvl', '1', false)]
            ],
            'admin' => [
                'label' => 'Admin (level 15)',
                'description' => 'priv-lvl 15: toàn quyền, vào thẳng chế độ enable.',
                'blocks' => [self::block('exec', 'priv-lvl', '15', false)]
            ]
        ];

        // Only for platforms that authorize each command against the Policy.
        $ciscoPolicyLevels = $ciscoLevels + [
            'custom_policy' => [
                'label' => 'Custom (level 7)',
                'description' => 'priv-lvl 7: chỉ chạy các lệnh trong Command Policy (bắt buộc chọn policy). Lệnh mà thiết bị đặt ở level 15 (vd: configure terminal) vẫn bị chặn nếu trên thiết bị chưa gán xuống level 7.',
                'requires_policy' => true,
                'blocks' => [self::block('exec', 'priv-lvl', '7', false)]
            ]
        ];

        return [
            'generic' => [
                'manufacturer' => 'generic',
                'label' => 'Other',
                'description' => 'Không gửi thuộc tính phân quyền; thiết bị tự quyết định mức quyền.',
                // Unknown device: a Policy is the only control, keep it available.
                'command_authorization' => true,
                'verified' => true,
                'default_level' => 'device',
                'levels' => ['device' => $deviceDefault]
            ],
            'cisco_ios' => [
                'manufacturer' => 'cisco',
                'label' => 'IOS/IOS-XE',
                'description' => 'Thiết bị cần: aaa authorization exec <list> group tacacs+. Policy cần: aaa authorization commands <level> <list> group tacacs+.',
                'command_authorization' => true,
                'verified' => true,
                'default_level' => 'readonly',
                'levels' => $ciscoPolicyLevels
            ],
            'cisco_smb' => [
                'manufacturer' => 'cisco',
                'label' => 'SMB Sx350/CBS',
                'description' => 'Thiết bị cần: aaa authentication login authorization <list> tacacs local. Không duyệt từng lệnh qua TACACS+, nên Policy không có tác dụng.',
                'command_authorization' => false,
                'verified' => true,
                'default_level' => 'readonly',
                'levels' => $ciscoLevels
            ],
            'cisco_c1300' => [
                'manufacturer' => 'cisco',
                'label' => 'C1300',
                'description' => 'Thiết bị cần: aaa authentication login authorization <list> tacacs local. Policy cần firmware 4.1.7.17+ và aaa authorization commands <level> <list> tacacs.',
                'command_authorization' => true,
                'verified' => true,
                'default_level' => 'readonly',
                'levels' => $ciscoPolicyLevels
            ],
            'cisco_nxos' => [
                'manufacturer' => 'cisco',
                'label' => 'NX-OS (cần thử)',
                'description' => 'Trả role qua shell:roles (optional). Theo tài liệu Cisco cho ACS 5.2, chưa thử trên thiết bị.',
                'command_authorization' => true,
                'verified' => false,
                'default_level' => 'operator',
                'levels' => [
                    'operator' => [
                        'label' => 'network-operator (read-only)',
                        'description' => 'Role network-operator: chỉ xem.',
                        'blocks' => [self::block('exec', 'shell:roles', '"network-operator"', true, true)]
                    ],
                    'admin' => [
                        'label' => 'network-admin',
                        'description' => 'Role network-admin: toàn quyền.',
                        'blocks' => [self::block('exec', 'shell:roles', '"network-admin"', true, true)]
                    ]
                ]
            ],
            'fortigate' => [
                'manufacturer' => 'fortinet',
                'label' => 'FortiGate',
                'description' => 'Trả admin profile qua service fortigate (admin_prof). Thiết bị cần admin wildcard với remote-auth, remote-group và accprofile-override enable. Không có profile hợp lệ thì dùng profile của admin wildcard.',
                'command_authorization' => false,
                'verified' => true,
                'default_level' => 'device',
                'levels' => [
                    'device' => $deviceDefault,
                    'super_admin' => [
                        'label' => 'super_admin',
                        'description' => 'Profile có sẵn: toàn quyền.',
                        'blocks' => [self::block('fortigate', 'admin_prof', 'super_admin')]
                    ],
                    'prof_admin' => [
                        'label' => 'prof_admin',
                        'description' => 'Profile có sẵn: quản trị trong VDOM được gán.',
                        'blocks' => [self::block('fortigate', 'admin_prof', 'prof_admin')]
                    ]
                ],
                'custom' => [
                    'label' => 'Custom admin profile',
                    'description' => 'Nhập đúng tên admin profile đã tạo trên FortiGate.',
                    'blocks' => [self::block('fortigate', 'admin_prof', '{value}')]
                ]
            ],
            'panos' => [
                'manufacturer' => 'paloalto',
                'label' => 'PAN-OS (cần thử)',
                'description' => 'Trả role qua PaloAlto-Admin-Role (service PaloAlto). Theo PAN-OS Admin Guide, chưa thử trên thiết bị; tac_plus không gửi được protocol=firewall.',
                'command_authorization' => false,
                'verified' => false,
                'default_level' => 'device',
                'levels' => [
                    'device' => $deviceDefault,
                    'superreader' => [
                        'label' => 'superreader',
                        'description' => 'Role có sẵn: chỉ xem.',
                        'blocks' => [self::block('PaloAlto', 'PaloAlto-Admin-Role', 'superreader')]
                    ],
                    'superuser' => [
                        'label' => 'superuser',
                        'description' => 'Role có sẵn: toàn quyền.',
                        'blocks' => [self::block('PaloAlto', 'PaloAlto-Admin-Role', 'superuser')]
                    ]
                ],
                'custom' => [
                    'label' => 'Custom admin role',
                    'description' => 'Nhập đúng tên admin role đã tạo trên firewall.',
                    'blocks' => [self::block('PaloAlto', 'PaloAlto-Admin-Role', '{value}')]
                ]
            ],
            'junos' => [
                'manufacturer' => 'juniper',
                'label' => 'Junos',
                'description' => 'Trả user mẫu qua local-user-name (service junos-exec). Thiết bị cần tạo user mẫu gắn login class; không khớp thì dùng user mẫu "remote".',
                'command_authorization' => false,
                'verified' => true,
                'default_level' => 'device',
                'levels' => [
                    'device' => [
                        'label' => 'Device default (remote)',
                        'description' => 'Không gửi local-user-name; thiết bị dùng user mẫu "remote".',
                        'blocks' => []
                    ]
                ],
                'custom' => [
                    'label' => 'Custom template user',
                    'description' => 'Nhập đúng tên user mẫu đã tạo trên thiết bị (set system login user <tên> class <class>).',
                    'blocks' => [self::block('junos-exec', 'local-user-name', '{value}')]
                ]
            ]
        ];
    }

    private static function block(
        string $service,
        string $name,
        string $value,
        bool $quote = true,
        bool $optional = false
    ): array {
        return [
            'service' => $service,
            'attributes' => [[
                'name' => $name,
                'value' => $value,
                'quote' => $quote,
                'optional' => $optional
            ]]
        ];
    }

    public static function supportsCommandPolicy(string $vendor): bool {
        return self::get($vendor)['command_authorization'];
    }

    public static function requiresPolicy(string $vendor, string $level, string $custom = ''): bool {
        $profile = self::get($vendor);
        $resolved = self::resolveLevel($vendor, $level, $custom);

        return !empty($profile['levels'][$resolved]['requires_policy']);
    }

    public static function supportsCustom(string $vendor): bool {
        return isset(self::get($vendor)['custom']);
    }

    public static function exists(string $vendor): bool {
        return array_key_exists($vendor, self::all());
    }

    public static function get(string $vendor): array {
        $vendors = self::all();

        return $vendors[$vendor] ?? $vendors[self::DEFAULT_VENDOR];
    }

    public static function manufacturerOf(string $vendor): string {
        return self::get($vendor)['manufacturer'];
    }

    public static function displayName(string $vendor): string {
        $profile = self::get($vendor);
        $manufacturers = self::manufacturers();

        return $manufacturers[$profile['manufacturer']].' / '.$profile['label'];
    }

    public static function isValidCustom(string $value): bool {
        return preg_match(self::CUSTOM_PATTERN, $value) === 1;
    }

    /**
     * Unknown or stale levels (e.g. after the device platform changed) fall
     * back to the platform's default level, the least privileged one.
     */
    public static function resolveLevel(string $vendor, string $level, string $custom = ''): string {
        $profile = self::get($vendor);

        if ($level === self::CUSTOM_LEVEL && isset($profile['custom']) && self::isValidCustom($custom)) {
            return self::CUSTOM_LEVEL;
        }

        return array_key_exists($level, $profile['levels'])
            ? $level
            : $profile['default_level'];
    }

    public static function levelLabel(string $vendor, string $level, string $custom = ''): string {
        $profile = self::get($vendor);
        $resolved = self::resolveLevel($vendor, $level, $custom);

        return $resolved === self::CUSTOM_LEVEL
            ? $profile['custom']['label'].': '.$custom
            : $profile['levels'][$resolved]['label'];
    }

    /**
     * Service blocks for a resolved level; the custom value is inserted only
     * after it passed isValidCustom().
     */
    public static function blocks(string $vendor, string $level, string $custom = ''): array {
        $profile = self::get($vendor);
        $resolved = self::resolveLevel($vendor, $level, $custom);

        if ($resolved !== self::CUSTOM_LEVEL) {
            return $profile['levels'][$resolved]['blocks'];
        }

        $blocks = $profile['custom']['blocks'];

        foreach ($blocks as &$block) {
            foreach ($block['attributes'] as &$attribute) {
                $attribute['value'] = str_replace('{value}', $custom, $attribute['value']);
            }
            unset($attribute);
        }
        unset($block);

        return $blocks;
    }
}
