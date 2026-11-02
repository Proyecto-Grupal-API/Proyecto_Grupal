<?php

return [
    // LOCAL ONLY / NOT PUBLISHED. No consumers or OAuth grants are provisioned here.
    'clients' => [],
    // Operator attestations require a separate authorized preflight; never inferred from a DB name.
    'enrollment_integrity_verified' => false,
    // Fixed collection/connection in DirectoryQuotaStore; never taken from the HTTP request.
    'quota_deployment' => null,
    'quota_generation' => null,
    'quota_store_verified' => false,
];
