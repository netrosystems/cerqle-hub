<?php

namespace Tests\Feature\Client;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientPhoneCountryCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_save_a_national_phone_with_a_country_code(): void
    {
        ['client' => $client, 'user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->put(route('client.settings.update'), [
                'client_phone_country' => 'BD',
                'client_phone' => '01712 345678',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('client.settings.index'));

        $this->assertSame('+8801712345678', $client->refresh()->phone);
    }

    public function test_invalid_phone_is_rejected_without_overwriting_the_saved_value(): void
    {
        ['client' => $client, 'user' => $user] = $this->createWorkspaceContext([
            'phone' => '+14155552671',
        ]);

        $this->actingAs($user)
            ->from(route('client.settings.index'))
            ->put(route('client.settings.update'), [
                'client_phone_country' => 'US',
                'client_phone' => '123',
            ])
            ->assertSessionHasErrors('client_phone')
            ->assertRedirect(route('client.settings.index'));

        $this->assertSame('+14155552671', $client->refresh()->phone);
    }

    public function test_client_can_remove_the_saved_phone_number(): void
    {
        ['client' => $client, 'user' => $user] = $this->createWorkspaceContext([
            'phone' => '+14155552671',
        ]);

        $this->actingAs($user)
            ->put(route('client.settings.update'), [
                'client_phone_country' => 'US',
                'client_phone' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($client->refresh()->phone);
    }

    public function test_phone_requires_a_supported_country_selection(): void
    {
        ['client' => $client, 'user' => $user] = $this->createWorkspaceContext([
            'phone' => '+14155552671',
        ]);

        $this->actingAs($user)
            ->from(route('client.settings.index'))
            ->put(route('client.settings.update'), [
                'client_phone_country' => 'ZZ',
                'client_phone' => '4155552671',
            ])
            ->assertSessionHasErrors('client_phone_country');

        $this->assertSame('+14155552671', $client->refresh()->phone);
    }

    public function test_existing_international_phone_is_split_for_the_settings_form(): void
    {
        ['user' => $user] = $this->createWorkspaceContext([
            'phone' => '+14155552671',
        ]);

        $this->actingAs($user)
            ->get(route('client.settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('client/Settings/Index')
                ->where('client.phone_region', 'US')
                ->where('client.phone_national', '(415) 555-2671')
                ->has('phoneCountries')
            );
    }
}
