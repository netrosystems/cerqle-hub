<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CsrfProbeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function plain_post_without_csrf_419s(): void
    {
        $this->post('/admin/clients/1/impersonate', [], [
            'X-CSRF-TOKEN' => 'definitely-not-a-real-token',
        ])->assertStatus(419);
    }
}
