<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AlertSeverity;
use App\Enums\NotificationChannelKind;
use App\Enums\ReportFrequency;
use App\Models\Project;
use App\Support\WebhookGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as Validation;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * A new channel for a project's alerts: up to 20 email addresses, or a
 * webhook URL that's allowed to be reached, with what it takes, whether it
 * waits for alerts to go unacknowledged, and when it keeps quiet.
 */
final class StoreNotificationChannelRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        assert($project instanceof Project);

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('notification_channels')->where('project_id', $project->id)],
            'kind' => ['required', Rule::enum(NotificationChannelKind::class)],
            'target' => ['required', 'string', 'max:2000'],
            'min_severity' => ['required', Rule::enum(AlertSeverity::class)],
            'notify_cleared' => ['required', 'boolean'],
            'notify_issues' => ['sometimes', 'boolean'],
            'report' => ['nullable', Rule::enum(ReportFrequency::class)],
            'escalate_after_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'quiet_from' => ['nullable', 'date_format:H:i', 'required_with:quiet_until', 'different:quiet_until'],
            'quiet_until' => ['nullable', 'date_format:H:i', 'required_with:quiet_from'],
            'timezone' => ['required', 'timezone:all'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $kind = NotificationChannelKind::tryFrom($this->string('kind')->value());
                if ($kind === null || $validator->errors()->has('target')) {
                    return;
                }

                $target = $this->string('target')->trim()->value();
                if ($kind === NotificationChannelKind::Email) {
                    $addresses = array_values(array_filter(preg_split('/[\s,;]+/', $target) ?: []));
                    $check = Validation::make(['addresses' => $addresses], ['addresses' => ['required', 'array', 'max:20'], 'addresses.*' => ['email:rfc']]);
                    if ($check->fails()) {
                        $validator->errors()->add('target', __('Enter up to 20 email addresses, separated by commas or new lines.'));
                    }

                    return;
                }

                try {
                    resolve(WebhookGuard::class)->check($kind, $target);
                } catch (InvalidArgumentException $invalidArgumentException) {
                    $validator->errors()->add('target', $invalidArgumentException->getMessage());
                }
            },
        ];
    }

    /**
     * @return array{name: string, kind: NotificationChannelKind, target: string, min_severity: AlertSeverity, notify_cleared: bool, notify_issues: bool, report: ReportFrequency|null, escalate_after_minutes: int|null, quiet_from: string|null, quiet_until: string|null, timezone: string}
     */
    public function channel(): array
    {
        return [
            'name' => $this->string('name')->value(),
            'kind' => NotificationChannelKind::from($this->string('kind')->value()),
            'target' => $this->string('target')->trim()->value(),
            'min_severity' => AlertSeverity::from($this->string('min_severity')->value()),
            'notify_cleared' => $this->boolean('notify_cleared'),
            'notify_issues' => $this->boolean('notify_issues'),
            'report' => $this->enum('report', ReportFrequency::class),
            'escalate_after_minutes' => $this->filled('escalate_after_minutes') ? $this->integer('escalate_after_minutes') : null,
            'quiet_from' => $this->filled('quiet_from') ? $this->string('quiet_from')->value() : null,
            'quiet_until' => $this->filled('quiet_until') ? $this->string('quiet_until')->value() : null,
            'timezone' => $this->string('timezone')->value(),
        ];
    }
}
