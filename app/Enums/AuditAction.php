<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What someone did that the organisation's audit log keeps.
 */
enum AuditAction: string
{
    case MemberAdded = 'member.added';
    case MemberRoleChanged = 'member.role_changed';
    case MemberRemoved = 'member.removed';
    case ApiTokenCreated = 'api_token.created';
    case ApiTokenRevoked = 'api_token.revoked';
    case SsoSaved = 'sso.saved';
    case SsoRemoved = 'sso.removed';
    case SsoLinked = 'sso.linked';
    case SsoUnlinked = 'sso.unlinked';
    case ProjectCreated = 'project.created';
    case ProjectDeleted = 'project.deleted';
    case UploadAdded = 'upload.added';
    case UploadDeleted = 'upload.deleted';
    case DeviceAdded = 'device.added';
    case DevicePasswordReset = 'device.password_reset';
    case DeviceDeleted = 'device.deleted';
    case OpcUaServerAdded = 'opcua_server.added';
    case OpcUaServerDeleted = 'opcua_server.deleted';
    case ChannelAdded = 'channel.added';
    case ChannelDeleted = 'channel.deleted';
    case TypeSaved = 'type.saved';
    case TypeDeleted = 'type.deleted';
    case TwinCreated = 'twin.created';
    case TwinRenamed = 'twin.renamed';
    case TwinDeleted = 'twin.deleted';
    case VersionSaved = 'twin.version_saved';
    case OpeningViewChanged = 'twin.opening_view_changed';
    case GatewayTokenIssued = 'gateway_token.issued';
    case GatewayTokenRevoked = 'gateway_token.revoked';
    case OpcUaWritesChanged = 'twin.opcua_writes_changed';
    case AlertAcknowledged = 'alert.acknowledged';
    case IssueRaised = 'issue.raised';
    case IssueAssigned = 'issue.assigned';
    case IssueClosed = 'issue.closed';
    case IssueReopened = 'issue.reopened';
    case IssueDeleted = 'issue.deleted';

    /**
     * @return array<string, string>
     */
    public static function areas(): array
    {
        return [
            'access' => 'Members, API tokens and single sign-on',
            'projects' => 'Projects, uploads and types',
            'twins' => 'Twins and versions',
            'connections' => 'Gateways, devices, OPC UA and channels',
            'operations' => 'Alerts and issues',
        ];
    }

    /**
     * @return list<self>
     */
    public static function in(string $area): array
    {
        return array_values(array_filter(self::cases(), fn (self $action): bool => $action->area() === $area));
    }

    public function label(): string
    {
        return match ($this) {
            self::MemberAdded => 'Added a member',
            self::MemberRoleChanged => 'Changed a role',
            self::MemberRemoved => 'Removed a member',
            self::ApiTokenCreated => 'Made an API token',
            self::ApiTokenRevoked => 'Revoked an API token',
            self::SsoSaved => 'Set up single sign-on',
            self::SsoRemoved => 'Turned off single sign-on',
            self::SsoLinked => 'Linked single sign-on',
            self::SsoUnlinked => 'Unlinked single sign-on',
            self::ProjectCreated => 'Created a project',
            self::ProjectDeleted => 'Deleted a project',
            self::UploadAdded => 'Uploaded to the library',
            self::UploadDeleted => 'Deleted an upload',
            self::DeviceAdded => 'Added a device',
            self::DevicePasswordReset => "Reset a device's password",
            self::DeviceDeleted => 'Deleted a device',
            self::OpcUaServerAdded => 'Added an OPC UA server',
            self::OpcUaServerDeleted => 'Deleted an OPC UA server',
            self::ChannelAdded => 'Added a notification channel',
            self::ChannelDeleted => 'Deleted a notification channel',
            self::TypeSaved => 'Saved a type to the library',
            self::TypeDeleted => 'Deleted a type from the library',
            self::TwinCreated => 'Created a twin',
            self::TwinRenamed => 'Renamed or described a twin',
            self::TwinDeleted => 'Deleted a twin',
            self::VersionSaved => 'Saved a version',
            self::OpeningViewChanged => 'Changed the opening view',
            self::GatewayTokenIssued => 'Issued a gateway token',
            self::GatewayTokenRevoked => 'Revoked a gateway token',
            self::OpcUaWritesChanged => 'Turned OPC UA writes on or off',
            self::AlertAcknowledged => 'Acknowledged an alert',
            self::IssueRaised => 'Raised an issue',
            self::IssueAssigned => 'Assigned an issue',
            self::IssueClosed => 'Closed an issue',
            self::IssueReopened => 'Reopened an issue',
            self::IssueDeleted => 'Deleted an issue',
        };
    }

    /**
     * The part of Meridian it belongs to, to filter the log by.
     */
    public function area(): string
    {
        return match ($this) {
            self::MemberAdded, self::MemberRoleChanged, self::MemberRemoved, self::ApiTokenCreated, self::ApiTokenRevoked,
            self::SsoSaved, self::SsoRemoved, self::SsoLinked, self::SsoUnlinked => 'access',
            self::ProjectCreated, self::ProjectDeleted, self::UploadAdded, self::UploadDeleted, self::TypeSaved, self::TypeDeleted => 'projects',
            self::DeviceAdded, self::DevicePasswordReset, self::DeviceDeleted, self::OpcUaServerAdded, self::OpcUaServerDeleted,
            self::GatewayTokenIssued, self::GatewayTokenRevoked, self::OpcUaWritesChanged, self::ChannelAdded, self::ChannelDeleted => 'connections',
            self::TwinCreated, self::TwinRenamed, self::TwinDeleted, self::VersionSaved, self::OpeningViewChanged => 'twins',
            self::AlertAcknowledged, self::IssueRaised, self::IssueAssigned, self::IssueClosed, self::IssueReopened, self::IssueDeleted => 'operations',
        };
    }
}
