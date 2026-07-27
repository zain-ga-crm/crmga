<?php
/**
 * ACL roles to seed (derived from the source system's 29 roles, blueprint §14).
 * Reference seed for spatie/laravel-permission — move into config/roles.php and refine
 * against the real acl_roles / acl_roles_actions data during Phase 1.
 *
 * Each role maps to a permission set of module x action (view/list/edit/delete/import/export),
 * to be defined per role in Phase 1.3.
 */

return [
    'Administrator',                       // is_admin
    'Appointment Setter - Regular',
    'Appointment Setter - Supervisor',
    'Assessment Score - Manager',
    'Assessment Score - Regular',
    'Assessment Score - Supervisor',
    'Associates Developer',
    'Client',
    'Client Development - Supervisor',
    'Client Development - User',
    'Email Templates',
    'In-Canada Module',
    'In-Canada Module - Supervisor',
    'Leads Only',
    'LMIA Inquiry - Regular User',
    'LMIA Lead - Regular',
    'LMIA Lead - Supervisor',
    'Only Email',
    'Recruiter',
    'Representatives Supervisor - Dildar',
    'Sales Representatives',
    'Sales Representatives - Dildar',
    'Sales Representatives - Jiya',
    'Study - Supervisor',
    'Study - New Regular',
    'USA - Regular',
    'USA - Supervisor',
];
