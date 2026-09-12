<?php

declare(strict_types=1);

namespace app\doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;

/**
 * 宽容版 timestamptz：兼容 pgsql 带/不带微秒的文本输出。
 *
 * DBAL 自带的 datetimetz_immutable 只认严格 `Y-m-d H:i:sO`，而 NOW() 默认带微秒，
 * 直接映射必炸。本类型多格式依次尝试，写入仍走标准格式。
 */
final class VarTimestamptzType extends DateTimeTzImmutableType
{
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if ($value === null || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        foreach (['Y-m-d H:i:s.uO', 'Y-m-d H:i:sO', 'Y-m-d H:i:s.u', 'Y-m-d H:i:s'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, (string) $value);
            if ($dt !== false) {
                return $dt;
            }
        }

        return parent::convertToPHPValue($value, $platform);
    }
}
