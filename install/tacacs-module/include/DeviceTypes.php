<?php

namespace Modules\Tacacs;

class DeviceTypes {

    public static function all(): array {
        return [
            'router' => [
                'label' => _('Router'),
                'description' => _('Thiết bị định tuyến mạng.')
            ],
            'switch' => [
                'label' => _('Switch'),
                'description' => _('Thiết bị chuyển mạch mạng.')
            ],
            'firewall' => [
                'label' => _('Firewall'),
                'description' => _('Thiết bị tường lửa bảo vệ và kiểm soát lưu lượng mạng.')
            ],
            'wireless' => [
                'label' => _('Wireless'),
                'description' => _('Wireless Controller hoặc thiết bị quản lý mạng Wi-Fi.')
            ],
            'loadbalancer' => [
                'label' => _('Load Balancer'),
                'description' => _('Thiết bị hoặc hệ thống cân bằng tải.')
            ],
            'server' => [
                'label' => _('Server'),
                'description' => _('Máy chủ được quản lý thông qua TACACS+.')
            ],
            'vpn' => [
                'label' => _('VPN'),
                'description' => _('VPN Gateway hoặc thiết bị cung cấp dịch vụ VPN.')
            ],
            'other' => [
                'label' => _('Other'),
                'description' => _('Thiết bị không thuộc các nhóm trên.')
            ]
        ];
    }

    public static function labels(): array {
        $labels = [];

        foreach (self::all() as $key => $type) {
            $labels[$key] = $type['label'];
        }

        return $labels;
    }

    public static function getDescription(string $type): string {
        $types = self::all();

        return $types[$type]['description'] ?? _('Other device type.');
    }
}
