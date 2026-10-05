<?php

namespace App\Support;

/** 渠道常量与中文标签（渠道均为本地模拟） */
class Channels
{
    public const WEBSITE = 'website';   // 官网
    public const SCREEN = 'screen';     // 店内屏
    public const DELIVERY = 'delivery'; // 外卖平台

    public const ALL = [self::WEBSITE, self::SCREEN, self::DELIVERY];

    public const LABELS = [
        self::WEBSITE => '官网',
        self::SCREEN => '店内屏',
        self::DELIVERY => '外卖平台',
    ];

    public static function label(string $channel): string
    {
        return self::LABELS[$channel] ?? $channel;
    }
}
