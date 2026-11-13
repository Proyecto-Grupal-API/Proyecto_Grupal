<?php
return [
    // E2-maintained exact canonical Council => institution_reference. No academic substitutions.
    'institutions' => json_decode((string) env('FINANCIAL_BUDGET_INSTITUTIONS_JSON', '{}'), true, 512, JSON_THROW_ON_ERROR),
];
