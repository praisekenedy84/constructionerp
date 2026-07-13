<?php

namespace App\Models;

use App\Enums\RequisitionAttachmentDocumentType;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequisitionAttachment extends Model
{
    use LogsActivity;

    public $timestamps = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'requisition_id',
        'file_url',
        'document_type',
        'uploaded_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => RequisitionAttachmentDocumentType::class,
            'created_at' => 'datetime',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
