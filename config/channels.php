<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 本地模拟的发布渠道
    |--------------------------------------------------------------------------
    | mode:
    |   sync_success       立即成功回执
    |   fail_first_attempt 每个发布的第 1 次尝试失败，重试后成功（模拟不稳定设备）
    |   delayed_receipt    先受理，delay_seconds 秒后才异步回执（模拟外卖平台延迟回执）
    */
    'channels' => [
        'website' => [
            'name' => '官网',
            'mode' => 'sync_success',
        ],
        'screen' => [
            'name' => '店内屏',
            'mode' => 'fail_first_attempt',
        ],
        'delivery' => [
            'name' => '外卖平台',
            'mode' => 'delayed_receipt',
            'delay_seconds' => (int) env('CHANNEL_DELIVERY_DELAY', 8),
        ],
    ],
];
