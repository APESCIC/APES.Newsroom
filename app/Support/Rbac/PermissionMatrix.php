<?php

namespace App\Support\Rbac;

/**
 * Read-only summary of who may do what. Kept in one place so the admin
 * matrix page, docs and tests describe the same three-role model.
 *
 * Editable permissions and new roles are out of scope (#817).
 */
final class PermissionMatrix
{
    /**
     * @return list<array{key: string, label: string, description: string}>
     */
    public static function roles(): array
    {
        return [
            [
                'key' => 'public',
                'label' => 'Public',
                'description' => 'Anyone with an account. They manage their own newsletters, profile and security, and read public and member content they are entitled to.',
            ],
            [
                'key' => 'staff',
                'label' => 'Staff',
                'description' => 'APES staff, volunteers and students in the newsroom.staff Cloudron group. They write and schedule posts and pages, send newsletters, moderate day-to-day content, and view contacts and metrics.',
            ],
            [
                'key' => 'admin',
                'label' => 'Admin',
                'description' => 'APES administrators in the newsroom.admin Cloudron group. They can do everything staff can, plus users and security, integration keys, mail sending, webhooks, site settings and this permission matrix. Sensitive changes need a recent sign-in.',
            ],
        ];
    }

    /**
     * High-level abilities, not every named route. Values are the minimum
     * role that may do the action (public / staff / admin / none).
     *
     * @return list<array{group: string, action: string, public: bool, staff: bool, admin: bool, note: string|null}>
     */
    public static function abilities(): array
    {
        return [
            [
                'group' => 'Public site and account',
                'action' => 'Read public articles and manage own account',
                'public' => true,
                'staff' => true,
                'admin' => true,
                'note' => null,
            ],
            [
                'group' => 'Publishing',
                'action' => 'Write, edit and schedule own posts and pages',
                'public' => false,
                'staff' => true,
                'admin' => true,
                'note' => null,
            ],
            [
                'group' => 'Publishing',
                'action' => 'Review and publish posts',
                'public' => false,
                'staff' => false,
                'admin' => true,
                'note' => 'Staff drafts stay with the author until an admin reviews them.',
            ],
            [
                'group' => 'Campaigns and lists',
                'action' => 'Send newsletters and manage subscribers',
                'public' => false,
                'staff' => true,
                'admin' => true,
                'note' => null,
            ],
            [
                'group' => 'People',
                'action' => 'View and edit public accounts on person pages',
                'public' => false,
                'staff' => true,
                'admin' => true,
                'note' => 'Staff may only act on Public accounts, never on Staff or Admin.',
            ],
            [
                'group' => 'People',
                'action' => 'Users list, lock, unlock, credential resets and deletion',
                'public' => false,
                'staff' => false,
                'admin' => true,
                'note' => 'Never on your own account; never on the last unlocked admin.',
            ],
            [
                'group' => 'Admin panel',
                'action' => 'Open Admin workspace, settings, audit log and this matrix',
                'public' => false,
                'staff' => false,
                'admin' => true,
                'note' => 'Gate: access-admin-area.',
            ],
            [
                'group' => 'Integrations',
                'action' => 'Manage integration keys, mail sending, webhooks and API tokens',
                'public' => false,
                'staff' => false,
                'admin' => true,
                'note' => 'Many of these need a re-confirmed sign-in within five minutes.',
            ],
            [
                'group' => 'Roles',
                'action' => 'Grant Staff or Admin in the app',
                'public' => false,
                'staff' => false,
                'admin' => false,
                'note' => 'Roles come only from Cloudron directory groups. Admins may demote non-SSO accounts to Public only.',
            ],
        ];
    }

    /**
     * @return array{roles: list<array{key: string, label: string, description: string}>, abilities: list<array{group: string, action: string, public: bool, staff: bool, admin: bool, note: string|null}>}
     */
    public static function forPage(): array
    {
        return [
            'roles' => self::roles(),
            'abilities' => self::abilities(),
        ];
    }
}
