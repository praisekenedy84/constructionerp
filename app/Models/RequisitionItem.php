<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequisitionItem extends Model
{
    use LogsActivity;

    protected $fillable = [
        'requisition_id',
        'boq_item_id',
        'inventory_item_id',
        'requisition_category_id',
        'description',
        'unit',
        'quantity',
        'fulfilled_quantity',
        'unit_cost',
        'line_total',
        'original_quantity',
        'original_unit_cost',
        'original_line_total',
        'original_description',
        'details',
        'recipient_id',
        'recipient_name',
        'position_id',
        'recipient_position',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'fulfilled_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
            'original_quantity' => 'decimal:3',
            'original_unit_cost' => 'decimal:2',
            'original_line_total' => 'decimal:2',
            'details' => 'array',
        ];
    }

    public function wasAmended(): bool
    {
        return $this->original_quantity !== null
            || $this->original_unit_cost !== null
            || $this->original_line_total !== null;
    }

    /**
     * Optional duration multiplier stored in details.days.
     * Returns null when the line does not use days.
     */
    public function days(): ?string
    {
        $days = $this->details['days'] ?? null;
        if ($days === null || $days === '') {
            return null;
        }

        $normalized = bcadd((string) $days, '0', 3);

        return bccomp($normalized, '0', 3) === 1 ? $normalized : null;
    }

    /**
     * Multiplier for money calculations: days when set, otherwise 1.
     */
    public function daysMultiplier(): string
    {
        return $this->days() ?? '1';
    }

    /**
     * Amount for a fulfilled (or remaining) quantity, including optional days.
     */
    public function amountForQuantity(string $quantity): string
    {
        return $this->amountForQuantityAtCost($quantity, (string) $this->unit_cost);
    }

    /**
     * Amount for a quantity at an explicit unit cost (e.g. fulfillment override).
     */
    public function amountForQuantityAtCost(string $quantity, string $unitCost): string
    {
        $base = bcmul(bcadd($quantity, '0', 3), bcadd($unitCost, '0', 2), 4);

        return bcmul($base, $this->daysMultiplier(), 2);
    }

    /**
     * Persist the actual fulfilled unit cost on the line (keeps original_* once).
     */
    public function applyFulfilledUnitCost(string $unitCost): void
    {
        $unitCost = bcadd($unitCost, '0', 2);
        $current = bcadd((string) $this->unit_cost, '0', 2);

        if (bccomp($unitCost, $current, 2) === 0) {
            return;
        }

        $updates = [
            'unit_cost' => $unitCost,
            'line_total' => $this->amountForQuantityAtCost((string) $this->quantity, $unitCost),
        ];

        if ($this->original_unit_cost === null) {
            $updates['original_unit_cost'] = $current;
        }

        if ($this->original_line_total === null) {
            $updates['original_line_total'] = bcadd((string) $this->line_total, '0', 2);
        }

        $this->update($updates);
        $this->refresh();
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RequisitionCategory::class, 'requisition_category_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }
}
