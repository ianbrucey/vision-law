<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Document storage (007, 007-D01)
    |--------------------------------------------------------------------------
    |
    | Object storage behind the DocumentStore abstraction. The driver is
    | bound in AppServiceProvider (DocumentStore::class → LocalDocumentStore).
    | Local disk is the deployed driver; S3-compatible is designed, not
    | deployed — swapping drivers is config, not a rewrite.
    |
    */

    'driver' => env('DOCUMENT_DRIVER', 'local'),

    // Filesystem disk used by LocalDocumentStore for blob bytes. The disk
    // root MUST stay outside the web root and is never exposed to views
    // or API responses (contract §Component props).
    'disk' => env('DOCUMENT_DISK', 'documents'),

    // Prefix (relative to the disk root) where infected blobs are moved.
    // Quarantined bytes are never served back by DocumentStore::get().
    'quarantine_prefix' => env('DOCUMENT_QUARANTINE_PREFIX', 'quarantine'),

    // Staging area for chunked-upload chunks (007-D05); T-02 owns the
    // session lifecycle, T-01 lays the config.
    'staging_prefix' => env('DOCUMENT_STAGING_PREFIX', 'staging'),

    /*
    |--------------------------------------------------------------------------
    | Upload limits (007-D05, C-01/C-02)
    |--------------------------------------------------------------------------
    */

    // Single-shot uploads above this are rejected with 413 before storage.
    'max_single_upload_bytes' => (int) env('DOCUMENT_MAX_SINGLE_UPLOAD_BYTES', 100 * 1024 * 1024),

    // Chunked uploads: fixed 8 MiB chunks, total bounded at 5 GiB.
    'chunk_size_bytes' => (int) env('DOCUMENT_CHUNK_SIZE_BYTES', 8 * 1024 * 1024),

    'max_chunked_upload_bytes' => (int) env('DOCUMENT_MAX_CHUNKED_UPLOAD_BYTES', 5 * 1024 * 1024 * 1024),

    // Idle chunked-upload sessions expire after 24h (007-D05).
    'upload_session_ttl_hours' => (int) env('DOCUMENT_UPLOAD_SESSION_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | MIME allowlist (C-04 / DOC-04)
    |--------------------------------------------------------------------------
    |
    | Sniffed MIME must be on this list for upload acceptance. The store
    | sniffs bytes with fileinfo (LocalDocumentStore::sniffMime) — the
    | client-supplied extension is never trusted.
    |
    */

    'allowed_mimes' => [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain',
        'text/csv',
        'image/png',
        'image/jpeg',
        'image/tiff',
        'application/vnd.ms-outlook',
        'message/rfc822',
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing engines (007-D02 — provisioned natively via apt in T-01)
    |--------------------------------------------------------------------------
    */

    'clamav' => [
        'socket' => env('CLAMAV_SOCKET', '/var/run/clamav/clamd.ctl'),
        'binary' => env('CLAMAV_BINARY', 'clamscan'),
        // Engine unreachable → upload stays `scanning`, never silently
        // marked clean (C-03); ops alert fires after this many minutes.
        'alert_after_minutes' => (int) env('CLAMAV_ALERT_AFTER_MINUTES', 5),
    ],

    'tesseract' => [
        'binary' => env('TESSERACT_BINARY', 'tesseract'),
        'language' => env('TESSERACT_LANG', 'eng'),
    ],

    'libreoffice' => [
        'binary' => env('LIBREOFFICE_BINARY', 'soffice'),
    ],

];
