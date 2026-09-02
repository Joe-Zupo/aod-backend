<?php

namespace App\Http\Resources;

use App\Models\CalloutDetection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CalloutDetection */
class CalloutDetectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'keyword' => $this->keyword,
            'normalized_keyword' => $this->normalized_keyword,
            'category' => $this->category,
            'start_ms' => $this->start_ms,
            'end_ms' => $this->end_ms,
            'confidence' => $this->confidence,
        ];
    }
}
