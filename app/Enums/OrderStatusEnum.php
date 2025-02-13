<?php

namespace App\Enums;

enum OrderStatusEnum: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAILED = 'failed';

    /**
     * Get a human-readable label for each status.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending Payment',
            self::PAID => 'Payment Successful',
            self::FAILED => 'Payment Failed',
        };
    }

    /**
     * Check if the current status matches a given status.
     *
     * @param string $status
     * @return bool
     */
    public function is(string $status): bool
    {
        return $this->value === $status;
    }

    /**
     * Get all enum values as an array.
     *
     * @return array
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all enum labels as an associative array (value => label).
     *
     * @return array
     */
    public static function labels(): array
    {
        return array_reduce(self::cases(), function ($carry, $case) {
            $carry[$case->value] = $case->label();
            return $carry;
        }, []);
    }
}
