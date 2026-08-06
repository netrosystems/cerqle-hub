<?php

namespace App\Modules\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactListOperation extends Model
{
    protected $fillable = [
        'workspace_id', 'segment_id', 'created_by', 'type', 'status', 'total',
        'processed', 'added', 'updated', 'skipped', 'skipped_existing_customer',
        'skipped_invalid_phone', 'skipped_malformed_row', 'skipped_duplicate_in_file',
        'options', 'source_path',
        'error_message', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'total' => 'integer',
            'processed' => 'integer',
            'added' => 'integer',
            'updated' => 'integer',
            'skipped' => 'integer',
            'skipped_existing_customer' => 'integer',
            'skipped_invalid_phone' => 'integer',
            'skipped_malformed_row' => 'integer',
            'skipped_duplicate_in_file' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }
}
