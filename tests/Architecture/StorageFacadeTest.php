<?php

namespace Tests\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * C-14 door 1: no controller under app/Http/Controllers references the
 * Storage facade.
 *
 * Rationale (03-contract.md, 00-brief.md non-goals): the foundation feature
 * has no file-transfer surface — file handling arrives in a later phase
 * (PLT-23+). Any Storage usage in a controller today would be an
 * unaudited persistence side-channel, so the door fails the build instead.
 */
class StorageFacadeTest extends TestCase
{
    public function test_controllers_never_reference_the_storage_facade(): void
    {
        $violations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path('Http/Controllers'))
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            // Matches `Storage::…` calls and the facade import. A bare word
            // in a comment ("the Storage facade") does not match.
            if (preg_match('/\bStorage::/', $contents) === 1
                || str_contains($contents, 'Illuminate\Support\Facades\Storage')) {
                $violations[] = $file->getPathname();
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Controllers referencing the Storage facade: '.implode(', ', $violations)
        );
    }
}
