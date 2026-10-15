<?php

namespace Modules\Tacacs;

/**
 * Vendor-specific privilege mapping.
 *
 * Shared by the frontend and the CLI config generator, so it must stay plain
 * data: no Zabbix functions such as the gettext wrapper here. Each access
 * level lists the tac_plus service blocks and AV pairs returned to the device.
 *
 * command_authorization: the device sends each command to the server, so a
 * command Policy is enforced. Without it a Policy would have no effect.
 *
 * Add a vendor only with a documented attribute set and a device to test it.
 */
class VendorProfiles {

    public const DEFAULT_VENDOR = 'generic';
    public const DEFAULT_LEVEL = 'default';

    public static function all(): array {
        $ciscoLevels = [
            'readonly' => [
                'label' => 'Read-only (level 1)',
                'description' => 'priv-lvl 1: chỉ xem, không vào được enable.',
                'attributes' => ['exec' => ['priv-lvl' => '1']]
            ],
            'admin' => [
                'label' => 'Admin (level 15)',
                'description' => 'priv-lvl 15: toàn quyền, vào thẳng chế độ enable.',
                'attributes' => ['exec' => ['priv-lvl' => '15']]
            ]
        ];

        return [
            'generic' => [
                'label' => 'Generic',
                'description' => 'Không gửi thuộc tính phân quyền; thiết bị tự quyết định mức quyền.',
                // Unknown device: a Policy is the only control, keep it available.
                'command_authorization' => true,
                'default_level' => 'device',
                'levels' => [
                    'device' => [
                        'label' => 'Device default',
                        'description' => 'Không gửi priv-lvl; mức quyền do cấu hình trên thiết bị.',
                        'attributes' => []
                    ]
                ]
            ],
            'cisco_ios' => [
                'label' => 'Cisco IOS/IOS-XE',
                'description' => 'Thiết bị cần: aaa authorization exec <list> group tacacs+. Policy cần: aaa authorization commands <level> <list> group tacacs+.',
                'command_authorization' => true,
                'default_level' => 'readonly',
                'levels' => $ciscoLevels
            ],
            'cisco_smb' => [
                'label' => 'Cisco SMB (Sx350/CBS)',
                'description' => 'Thiết bị cần: aaa authentication login authorization <list> tacacs local. Không duyệt từng lệnh qua TACACS+, nên Policy không có tác dụng.',
                'command_authorization' => false,
                'default_level' => 'readonly',
                'levels' => $ciscoLevels
            ],
            'cisco_c1300' => [
                'label' => 'Cisco C1300',
                'description' => 'Thiết bị cần: aaa authentication login authorization <list> tacacs local. Policy cần firmware 4.1.7.17+ và aaa authorization commands <level> <list> tacacs.',
                'command_authorization' => true,
                'default_level' => 'readonly',
                'levels' => $ciscoLevels
            ]
        ];
    }

    public static function supportsCommandPolicy(string $vendor): bool {
        return self::get($vendor)['command_authorization'];
    }

    public static function exists(string $vendor): bool {
        return array_key_exists($vendor, self::all());
    }

    public static function get(string $vendor): array {
        $vendors = self::all();

        return $vendors[$vendor] ?? $vendors[self::DEFAULT_VENDOR];
    }

    /**
     * Unknown or stale levels (e.g. after the device vendor changed) fall back
     * to the vendor's default level, which is the least privileged one.
     */
    public static function resolveLevel(string $vendor, string $level): string {
        $profile = self::get($vendor);

        return array_key_exists($level, $profile['levels'])
            ? $level
            : $profile['default_level'];
    }

    public static function levelLabel(string $vendor, string $level): string {
        $profile = self::get($vendor);

        return $profile['levels'][self::resolveLevel($vendor, $level)]['label'];
    }

    public static function attributes(string $vendor, string $level): array {
        $profile = self::get($vendor);

        return $profile['levels'][self::resolveLevel($vendor, $level)]['attributes'];
    }
}
