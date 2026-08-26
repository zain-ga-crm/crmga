<?php

return [
    // §21 Q6's own open-question default: "retention period before a
    // soft-deleted record can be hard-deleted -> 90 days." crm:prune-soft-
    // deleted reads this; nothing schedules that command yet (deliberately —
    // it stays a manual, --force-gated operation until it's been run and
    // reviewed a few times).
    'soft_delete_retention_days' => (int) env('RECORD_RETENTION_DAYS', 90),
];
