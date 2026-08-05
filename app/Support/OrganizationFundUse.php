<?php

namespace App\Support;

/**
 * Allowed purposes for administrative cash. Spent only via indirect
 * expenses; payroll posts as Salaries. Office stock and event inventory are
 * tracked as overhead subtypes until dedicated inventory modules exist.
 */
final class OrganizationFundUse
{
    public const GENERAL = 'Administrative';

    public const RENT = 'Rent';

    public const UTILITIES = 'Utilities';

    public const SALARIES = 'Salaries';

    public const OFFICE_STOCK = 'Office Stock';

    public const EVENT_INVENTORY = 'Event Inventory';

    /**
     * @return list<string>
     */
    public static function subtypes(): array
    {
        return [
            self::GENERAL,
            self::RENT,
            self::UTILITIES,
            self::SALARIES,
            self::OFFICE_STOCK,
            self::EVENT_INVENTORY,
        ];
    }

    /**
     * Bucket used on the administrative cash lifecycle page.
     */
    public static function bucket(?string $subType): string
    {
        return match ($subType) {
            self::SALARIES => 'payroll',
            self::OFFICE_STOCK => 'office_stock',
            self::EVENT_INVENTORY => 'event_inventory',
            default => 'overhead',
        };
    }

    public static function bucketLabel(string $bucket): string
    {
        return match ($bucket) {
            'payroll' => 'Payroll / salaries',
            'office_stock' => 'Office stock',
            'event_inventory' => 'Event inventory',
            'opening' => 'Opening utilization',
            default => 'Overhead / administrative',
        };
    }
}
