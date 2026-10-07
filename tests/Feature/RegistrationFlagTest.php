<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 001-D09: self-registration is gated by the FORTIFY_REGISTRATION flag. When
 * the flag is off, the registration routes are never registered (404).
 *
 * The flag is read at config load, so it must be set before the application
 * boots — hence before parent::setUp(). A dedicated class keeps the env
 * mutation away from the other auth tests.
 */
class RegistrationFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        putenv('FORTIFY_REGISTRATION=false');
        $_ENV['FORTIFY_REGISTRATION'] = 'false';
        $_SERVER['FORTIFY_REGISTRATION'] = 'false';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('FORTIFY_REGISTRATION');
        unset($_ENV['FORTIFY_REGISTRATION'], $_SERVER['FORTIFY_REGISTRATION']);

        parent::tearDown();
    }

    public function test_registration_routes_absent_when_flag_off(): void
    {
        $this->postJson('/register', [
            'name' => 'Nope Nobody',
            'email' => 'nope@example.test',
            'password' => 'Correct-Horse-99-Battery',
        ])->assertNotFound();

        // Other auth routes are unaffected.
        $this->postJson('/login', [
            'email' => 'nobody@example.test',
            'password' => 'wrong',
        ])->assertStatus(422);
    }
}
