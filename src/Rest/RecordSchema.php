<?php

declare(strict_types=1);

namespace CoaVault\Rest;

/**
 * Shapes records for public REST responses (strips internal source/migration data).
 */
final class RecordSchema
{
    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public static function public_shape(array $record): array
    {
        unset($record['source']);
        return $record;
    }

    /**
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    public static function public_list(array $records): array
    {
        return array_map([self::class, 'public_shape'], $records);
    }
}
