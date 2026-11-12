<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\NotificationChannelKind;
use InvalidArgumentException;

/**
 * Keeps webhooks off private networks. Meridian posts to URLs its users give
 * it, so a URL that points inside the network it runs in (the database, a
 * metadata service) is refused, when it's saved and again before each send,
 * and the send then connects to the address that was checked. Slack and
 * Teams URLs must be on their own domains.
 */
final readonly class WebhookGuard
{
    /** The hosts Teams workflow webhooks live under. */
    private const array TEAMS_HOSTS = ['.webhook.office.com', '.logic.azure.com', '.environment.api.powerplatform.com'];

    /**
     * Checks a URL for a kind of channel, and returns the address to connect
     * to (null for Slack and Teams, whose domains are trusted).
     *
     * @throws InvalidArgumentException with the reason, for people to read
     */
    public function check(NotificationChannelKind $kind, string $url): ?string
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? mb_strtolower(mb_trim($parts['host'] ?? '', '[]')) : '';
        $scheme = is_array($parts) ? mb_strtolower($parts['scheme'] ?? '') : '';
        $private = config()->boolean('meridian.notifications.allow_private_webhooks');

        throw_if($host === '' || ! in_array($scheme, $private ? ['https', 'http'] : ['https'], true), InvalidArgumentException::class, __('The URL must start with https://.'));

        if ($kind === NotificationChannelKind::Slack) {
            throw_unless($host === 'hooks.slack.com', InvalidArgumentException::class, __('A Slack webhook URL starts with https://hooks.slack.com/.'));

            return null;
        }

        if ($kind === NotificationChannelKind::Teams) {
            throw_unless(collect(self::TEAMS_HOSTS)->contains(fn (string $suffix): bool => str_ends_with($host, $suffix)), InvalidArgumentException::class, __('A Teams webhook URL comes from a Teams workflow, on webhook.office.com, logic.azure.com or powerplatform.com.'));

            return null;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);

        throw_if($addresses === [], InvalidArgumentException::class, __(":host can't be found.", ['host' => $host]));

        if (! $private) {
            foreach ($addresses as $address) {
                throw_if(
                    filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false,
                    InvalidArgumentException::class,
                    __(":host is on a private network, which webhooks can't reach.", ['host' => $host]),
                );
            }
        }

        return $addresses[0];
    }
}
