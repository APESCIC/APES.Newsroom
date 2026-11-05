<?php

use App\Enums\Role;

return [

    /*
    |--------------------------------------------------------------------
    | LDAP group to role mapping
    |--------------------------------------------------------------------
    |
    | Maps Cloudron LDAP group identifiers to application Role values.
    | Keys must match the values returned in each user's `memberof`
    | attribute exactly (case-insensitive) — run the ldapsearch command
    | in docs/deployment.md to discover the real group names after
    | creating groups in Cloudron.
    |
    | Cloudron may return a full DN (`cn=newsroom.staff,ou=groups,dc=cloudron`).
    | StaffReconciler also matches the CN RDN extracted from a DN, so map
    | keys can be CNs. Include local OpenLDAP hyphenated CNs and the live
    | Cloudron dotted group names used on the directory.
    |
    */
    'ldap_group_map' => [
        // Local OpenLDAP (docker/openldap/bootstrap.ldif)
        'newsroom-staff' => Role::Staff,
        'newsroom-admins' => Role::Admin,
        // Live Cloudron groups (memberof CNs)
        'newsroom.staff' => Role::Staff,
        'newsroom.admin' => Role::Admin,
    ],

];
