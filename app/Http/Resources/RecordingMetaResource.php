<?php

namespace App\Http\Resources;

use App\Models\AodRecord;
use App\Models\VodRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Metadata-only view of one AOD or VOD record: identity and file facts, never a
 * URL or disk path. Both record types carry the same four fields.
 *
 * @mixin AodRecord
 * @mixin VodRecord
 */
class RecordingMetaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => (int) $this->size_bytes,
            'client_started_at' => $this->client_started_at?->toIso8601String(),
        ];
    }
}
