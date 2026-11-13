<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueComment>
 */
final class IssueCommentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'issue_id' => Issue::factory(),
            'author_id' => User::factory(),
            'body' => 'About a metre long, along the joint.',
        ];
    }
}
