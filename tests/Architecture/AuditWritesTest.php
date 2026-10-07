<?php

namespace Tests\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * C-14 door 2: audit_events rows are written ONLY through
 * App\Services\AuditLogger.
 *
 * The contract (03-contract.md §Audit events) makes AuditLogger the sole
 * writer so the privileged-key blocklist, the per-org advisory lock, and
 * the hash chain can never be bypassed by a direct insert. Reads
 * (AuditEvent::query()->…->get()/paginate()) are unrestricted — the door
 * governs writes.
 *
 * Scan scope is app/ only, per the T-07 plan: tests/Helpers and seeders may
 * insert via AuditLogger or factories.
 */
class AuditWritesTest extends TestCase
{
    public function test_audit_events_inserts_happen_only_inside_audit_logger(): void
    {
        $patterns = [
            'AuditEvent::create(' => '/\bAuditEvent::create\s*\(/',
            'AuditEvent::insert(' => '/\bAuditEvent::insert\s*\(/',
            "DB::table('audit_events')" => '/\bDB::table\s*\(\s*[\'"]audit_events[\'"]\s*\)/',
            'AuditEvent::query()->insert(' => '/\bAuditEvent::query\s*\(\s*\)\s*->\s*insert\s*\(/',
        ];

        $violations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path())
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            // The sole sanctioned writer.
            if (str_ends_with($path, 'Services/AuditLogger.php')) {
                continue;
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            foreach ($patterns as $label => $pattern) {
                if (preg_match($pattern, $contents) === 1) {
                    $violations[] = "{$path} contains {$label}";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "audit_events writes outside App\\Services\\AuditLogger:\n".implode("\n", $violations)
        );
    }
}
