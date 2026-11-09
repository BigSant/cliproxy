<?php

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;

/**
 * A DATETIME column holding the instant in UTC. Doctrine's own datetime_immutable writes the wall-clock time of PHP's
 * time zone without its offset, so an hour of autumn is stored twice and reads back an hour off: usage windows and
 * quota resets across the DST change would be wrong. Values read back are in PHP's
 * time zone, like the clock's, so compare them in seconds: modify('+1 hour') adds wall-clock time across the change too.
 */
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    public const string NAME = 'utc_datetime_immutable';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return parent::convertToDatabaseValue(
            $value instanceof \DateTimeImmutable ? $value->setTimezone(new \DateTimeZone('UTC')) : $value,
            $platform,
        );
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if ($value === null || $value instanceof \DateTimeImmutable) {
            return $value;
        }
        $utc = \DateTimeImmutable::createFromFormat('!'.$platform->getDateTimeFormatString(), $value, new \DateTimeZone('UTC'));
        if ($utc === false) {
            throw InvalidFormat::new($value, self::class, $platform->getDateTimeFormatString());
        }

        return $utc->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
}
