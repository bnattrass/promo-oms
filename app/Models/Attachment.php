<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    public const TYPES = [
        'customer_po' => 'Customer purchase order',
        'artwork' => 'Artwork / logo',
        'proof' => 'Proof',
        'delivery_note' => 'Delivery note / POD',
        'invoice' => 'Invoice',
        'other' => 'Other',
    ];

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (Attachment $a) {
            $a->uploaded_by ??= auth()->id();
            if ($a->path && Storage::disk($a->disk ?? 'local')->exists($a->path)) {
                $a->size ??= Storage::disk($a->disk ?? 'local')->size($a->path);
                $a->mime_type ??= Storage::disk($a->disk ?? 'local')->mimeType($a->path) ?: null;
            }
        });

        // Remove the stored file with the record.
        static::deleted(fn (Attachment $a) => Storage::disk($a->disk)->delete($a->path));
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->size;

        return match (true) {
            $bytes >= 1048576 => number_format($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => number_format($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
