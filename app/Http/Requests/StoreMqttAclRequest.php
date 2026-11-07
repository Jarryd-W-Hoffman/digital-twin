<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A topic the broker asks whether a client may use: `acc` is 1 to receive,
 * 2 to publish and 4 to subscribe.
 */
final class StoreMqttAclRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:100'],
            'topic' => ['required', 'string', 'max:65535'],
            'acc' => ['required', 'integer'],
        ];
    }
}
