<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexAuditEventsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'area' => ['nullable', Rule::in(array_keys(AuditAction::areas()))],
            'since' => ['nullable', 'date'],
        ];
    }

    public function area(): ?string
    {
        return $this->filled('area') ? $this->string('area')->value() : null;
    }

    /**
     * An organisation's events, newest first, in the area and since the
     * time asked for.
     *
     * @return Builder<AuditEvent>
     */
    public function events(Organisation $organisation): Builder
    {
        $area = $this->area();
        $since = $this->filled('since') ? CarbonImmutable::parse($this->string('since')->value()) : null;

        return AuditEvent::query()
            ->where('organisation_id', $organisation->id)
            ->when($area !== null, fn (Builder $query): Builder => $query->whereIn('action', AuditAction::in((string) $area)))
            ->when($since instanceof CarbonImmutable, fn (Builder $query): Builder => $query->where('created_at', '>=', $since))
            ->latest()
            ->latest('id');
    }
}
