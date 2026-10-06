<?php

/*
 * Kanban module strings (English).
 *
 * The board is multi-mailbox and multi-medium: stage names are NOT defined
 * here, they are configured per mailbox in /kanban/settings.
 */

return [

    'title' => 'Board',

    // ------------------------------ filters ------------------------------
    'filter_mailbox'     => 'Mailbox',
    'filter_assignee'    => 'Assignee',
    'all_mailboxes'      => 'All mailboxes',
    'all_assignees'      => 'Anyone',
    'assignee_none'      => 'Unassigned',
    'assignee_me'        => 'Assigned to me',
    'search_placeholder' => 'Search subject, customer or email…',
    'show_stale_only'    => 'Waiting for a reply',
    'refresh'            => 'Refresh',
    'settings'           => 'Stages',
    'legend_medium'      => 'Message type:',

    // -------------------------------- board ------------------------------
    'empty_column' => 'No conversations',
    'load_more'    => 'Load more',
    'wip_over'     => 'Over the limit',
    'no_subject'   => '(no subject)',
    'no_assignee'  => 'Unassigned',
    'stale_hint'   => 'Waiting for a reply for :count days',

    // --------------------------- card quick actions -----------------------
    'card_actions'   => 'Actions',
    'card_assign_me' => 'Assign to me',
    'card_unassign'  => 'Unassign',
    'card_active'    => 'Mark as active',
    'card_pending'   => 'Mark as pending',
    'card_closed'    => 'Close conversation',
    'card_open'      => 'Open conversation',
    'move_to'        => 'Move to',

    // ------------------------------- settings -----------------------------
    'settings_title'    => 'Board stages',
    'settings_help'     => 'Stages are the board columns and are configured per mailbox. Drag cards between them to change a conversation status without opening it.',
    'settings_mailbox'  => 'Mailbox',
    'stage_name'        => 'Name',
    'stage_color'       => 'Colour',
    'stage_wip'         => 'Limit',
    'stage_status'      => 'Change status to',
    'stage_add'         => 'Add stage',
    'stage_remove'      => 'Remove',
    'stage_wip_hint'    => '0 = no limit. When exceeded the counter turns red (a warning only).',
    'stage_status_hint' => 'Optional: moving a conversation here also changes its status.',
    'settings_save'     => 'Save stages',
    'settings_reset'    => 'Restore defaults',
    'settings_saved'    => 'Stages saved.',
    'settings_reset_ok' => 'Default stages restored.',
    'settings_none'     => 'No stages defined. Add at least one.',
    'status_none'       => 'Do not change the status',
    'stages'            => 'Stages',

    // -------------------------------- errors ------------------------------
    'error_mailbox'   => 'You do not have access to any mailbox to view the board.',
    'error_not_found' => 'The conversation does not exist.',
    'error_forbidden' => 'You are not allowed to view this conversation.',
    'error_update'    => 'You are not allowed to modify this conversation.',
    'error_stage'     => 'That stage does not belong to the conversation mailbox.',
    'error_generic'   => 'The operation could not be completed.',

    // ------------------------------- actions ------------------------------
    'moved'            => 'Conversation moved to “:stage”.',
    'moved_and_status' => 'Conversation moved to “:stage” and status updated.',

    // ------------------------------- mediums ------------------------------
    'media_email'    => 'Email',
    'media_whatsapp' => 'WhatsApp',
    'media_phone'    => 'Phone',
    'media_sms'      => 'SMS',
    'media_chat'     => 'Chat',
    'media_custom'   => 'Other',

    // --------------------------------- age --------------------------------
    'age_minutes' => ':count min ago',
    'age_hours'   => ':count h ago',
    'age_days'    => ':count d ago',
    'age_months'  => ':count month ago|:count months ago',

    // ---------------------------- no mailboxes ----------------------------
    'no_mailboxes_title' => 'Nothing to show yet',
    'no_mailboxes_help'  => 'The board shows open conversations from the mailboxes you have access to. Ask an administrator to grant you access to a mailbox to get started.',
];
