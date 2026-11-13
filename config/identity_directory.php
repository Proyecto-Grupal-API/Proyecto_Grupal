<?php

return [
    // LOCAL ONLY / NOT PUBLISHED. No consumers or OAuth grants are provisioned here.
    'clients' => json_decode((string) env('IDENTITY_DIRECTORY_CLIENTS_JSON', '{}'), true, 512, JSON_THROW_ON_ERROR),
    // In-process Community audience clients, exact authorized program IDs and daily_reference_limit.
    'academic_clients' => json_decode((string) env('IDENTITY_ACADEMIC_CLIENTS_JSON', '{}'), true, 512, JSON_THROW_ON_ERROR),
    'community_academic_client' => env('COMMUNITY_ACADEMIC_DIRECTORY_CLIENT'),
    // Operator attestations require a separate authorized preflight; never inferred from a DB name.
    'enrollment_integrity_verified' => env('IDENTITY_ENROLLMENT_INTEGRITY_VERIFIED', false),
    // Fixed collection/connection in DirectoryQuotaStore; never taken from the HTTP request.
    'quota_deployment' => env('IDENTITY_DIRECTORY_QUOTA_DEPLOYMENT'),
    'quota_generation' => env('IDENTITY_DIRECTORY_QUOTA_GENERATION'),
    'quota_store_verified' => env('IDENTITY_DIRECTORY_QUOTA_VERIFIED', false),
];
