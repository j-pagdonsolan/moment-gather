<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 9 — security-rate-limiting.
 *
 * Smoke test for the uploads-area defense-in-depth `.htaccess` (R4.2, R4.3).
 *
 * IMPORTANT: This PHPUnit test does NOT and CANNOT prove that Apache actually
 * refuses to execute a PHP file placed in the uploads area — PHPUnit runs
 * through PHP directly, not through the Apache request pipeline that honors
 * `.htaccess`. The ACTUAL non-execution behavior is a documented MANUAL check
 * (final verification task 8, item d: "a `.php` placed in `storage/app/public`
 * is served as non-executable by Apache").
 *
 * What this test guarantees instead: the protective directives are PRESENT and
 * TRACKED in the tracked `storage/app/public/.htaccess` file, so the
 * defense-in-depth measure cannot silently disappear from version control.
 */
class MaliciousFileProtectionTest extends TestCase
{
    #[Test]
    public function uploads_area_htaccess_file_exists(): void
    {
        $path = storage_path('app/public/.htaccess');

        $this->assertTrue(
            File::exists($path),
            'The tracked uploads-area .htaccess is missing at storage/app/public/.htaccess.'
        );
    }

    #[Test]
    public function uploads_area_htaccess_contains_protective_directives(): void
    {
        $path = storage_path('app/public/.htaccess');
        $contents = File::get($path);

        // mod_php engine is disabled for user-uploaded content.
        $this->assertStringContainsString(
            'php_flag engine off',
            $contents,
            '.htaccess must disable the PHP engine (php_flag engine off).'
        );

        // Script files are denied outright.
        $this->assertStringContainsString(
            'Require all denied',
            $contents,
            '.htaccess must deny access to script files (Require all denied).'
        );

        // A FilesMatch directive targeting php/phtml (and friends) exists.
        $this->assertMatchesRegularExpression(
            '/<FilesMatch\s+"[^"]*\bphp\b[^"]*\bphtml\b[^"]*"\s*>/i',
            $contents,
            '.htaccess must contain a FilesMatch directive matching php/phtml.'
        );

        // Handler and type mappings for scripts are removed.
        $this->assertStringContainsString(
            'RemoveHandler',
            $contents,
            '.htaccess must remove script handlers (RemoveHandler).'
        );

        $this->assertStringContainsString(
            'RemoveType',
            $contents,
            '.htaccess must remove script types (RemoveType).'
        );
    }
}
