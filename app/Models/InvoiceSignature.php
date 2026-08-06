<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceSignature extends Model
{
    use LogsActivity;

    protected $fillable = [
        'invoice_id',
        'signature_type',
        'signature_file',
        'signed_by',
        'signed_date',
    ];

    protected function casts(): array
    {
        return ['signed_date' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }
}
