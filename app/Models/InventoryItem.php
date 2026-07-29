<?php

namespace App\Models;

use App\Enums\InventoryItemCategory;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'unit',
        'category',
        'reorder_point',
    ];

    protected function casts(): array
    {
        return [
            'category' => InventoryItemCategory::class,
            'reorder_point' => 'decimal:3',
        ];
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(InventoryIssue::class);
    }

    public static function generateUniqueCode(string $name): string
    {
        $base = static::abbreviateName($name);

        $code = $base;
        $suffix = 2;

        while (static::withTrashed()->where('code', $code)->exists()) {
            $tail = '-'.$suffix;
            $code = str($base)->substr(0, 20 - strlen($tail))->trim('-')->append($tail)->toString();
            $suffix++;
        }

        return $code;
    }

    public static function abbreviateName(string $name): string
    {
        $tokens = preg_split('/[^A-Za-z0-9]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = [];
        $numbers = [];

        foreach ($tokens as $token) {
            if (preg_match('/\d/', $token)) {
                if (preg_match('/(\d+)([A-Za-z]{0,3})/i', $token, $matches)) {
                    $numbers[] = strtoupper($matches[1].$matches[2]);
                }

                continue;
            }

            $letters[] = strtoupper($token[0]);
        }

        if ($letters === [] && $numbers === []) {
            return 'ITEM';
        }

        // Single-word names with no numbers: use first 3–4 letters (HAMMER → HAMM).
        if (count($letters) === 1 && $numbers === [] && isset($tokens[0])) {
            $letters = [strtoupper(substr($tokens[0], 0, 4))];
        }

        $prefix = implode('', $letters);
        $base = $numbers === [] ? $prefix : $prefix.'-'.implode('-', $numbers);

        return str($base)->substr(0, 16)->trim('-')->toString() ?: 'ITEM';
    }
}
