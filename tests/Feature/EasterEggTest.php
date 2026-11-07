<?php

namespace Tests\Feature;

use App\Models\ServiceAccount;
use App\Models\User;
use App\Notifications\AccessGranted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EasterEggTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_is_public_and_plays_the_video(): void
    {
        $this->get(route('easter-egg'))
            ->assertOk()
            ->assertSee('Piracy. It’s a crime.')
            ->assertSee(asset('easter-egg/narren-easter-egg.mp4'), false)
            ->assertSee(asset('easter-egg/poster.jpg'), false)
            ->assertSee('<video controls playsinline preload="metadata"', false);

        $this->assertFileExists(public_path('easter-egg/narren-easter-egg.mp4'));
        $this->assertFileExists(public_path('easter-egg/poster.jpg'));
    }

    public function test_the_page_is_translated(): void
    {
        app()->setLocale('de');

        $this->get(route('easter-egg'))->assertOk()->assertSee('Piraterie ist ein Verbrechen.');
    }

    public function test_the_mail_footer_links_to_the_page(): void
    {
        $mail = (new AccessGranted(ServiceAccount::factory()->create()))->toMail(new User);

        $html = (string) $mail->render();

        $this->assertStringContainsString('<a href="'.route('easter-egg').'"', $html);
        $this->assertStringContainsString('All rights reserved.</a>', $html);

        app()->setLocale('de');
        $html = (string) $mail->render();

        $this->assertStringContainsString('<a href="'.route('easter-egg').'"', $html);
        $this->assertStringContainsString('Alle Rechte vorbehalten.</a>', $html);
    }
}
