<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Kemasan = 'kemasan';
    case Alat = 'alat';
    case BahanToko = 'bahan_toko';
    case Upah = 'upah';
    case ListrikAir = 'listrik_air';
    case Transportasi = 'transportasi';
    case SewaRetribusi = 'sewa_retribusi';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Kemasan => 'Plastik dan kemasan',
            self::Alat => 'Alat',
            self::BahanToko => 'Bahan toko',
            self::Upah => 'Upah',
            self::ListrikAir => 'Listrik, air, pulsa',
            self::Transportasi => 'Transportasi',
            self::SewaRetribusi => 'Sewa dan retribusi',
            self::Lainnya => 'Lainnya',
        };
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(static fn (self $category): string => $category->value, self::cases());
    }

    /** @return list<array{code: string, label: string}> */
    public static function options(): array
    {
        return array_map(static fn (self $category): array => [
            'code' => $category->value,
            'label' => $category->label(),
        ], self::cases());
    }

    public static function labelFor(?string $code): ?string
    {
        return $code === null ? null : self::tryFrom($code)?->label();
    }
}
