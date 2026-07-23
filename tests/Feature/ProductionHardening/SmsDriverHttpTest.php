<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\Broadcasting\Services\Sms\SmsBdDriver;
use App\Modules\Broadcasting\Services\Sms\MessageBirdDriver;
use App\Modules\Broadcasting\Services\Sms\TwilioDriver;
use App\Modules\Broadcasting\Services\Sms\AlarisSmsDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests SMS driver HTTP interactions with Http::fake().
 */
class SmsDriverHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_twilio_driver_sends_to_correct_url_and_returns_sid(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM123', 'status' => 'queued'], 201),
        ]);

        $driver = new TwilioDriver('ACtest', 'token', '+15005550006');
        $result = $driver->send('+16175551234', 'Hello');

        $this->assertTrue($result->success);
        $this->assertSame('SM123', $result->messageId);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.twilio.com') &&
                $request->data()['To'] === '+16175551234' &&
                $request->data()['Body'] === 'Hello';
        });
    }

    public function test_smsbd_driver_returns_real_message_id(): void
    {
        Http::fake([
            'api.smsbd.com/*' => Http::response(['Message_ID' => 'BD_MSG_789'], 200),
        ]);

        $driver = new SmsBdDriver('key123', 'SENDER');
        $result = $driver->send('+8801712345678', 'Test msg');

        $this->assertTrue($result->success);
        $this->assertSame('BD_MSG_789', $result->messageId);
    }

    public function test_messagebird_sends_single_recipient_as_an_array(): void
    {
        Http::fake([
            'rest.messagebird.com/messages' => Http::response(['id' => 'mb-123'], 201),
        ]);

        $result = (new MessageBirdDriver('key123', 'Cerqle'))->send('+8801712345678', 'Test msg');

        $this->assertTrue($result->success);
        $this->assertSame('mb-123', $result->messageId);
        Http::assertSent(fn ($request) => $request['recipients'] === ['8801712345678']);
    }

    public function test_alaris_driver_sends_with_basic_auth_and_tracks_the_returned_message_id(): void
    {
        Http::fake([
            'https://sms.alaris.test:8002/api?command=submit' => Http::response([
                ['dnis' => '8801712345678', 'message_id' => 'ALARIS_123'],
            ], 200),
        ]);

        $result = (new AlarisSmsDriver(
            'https://sms.alaris.test:8002/api',
            'client-user',
            'client-password',
            'WISPER',
        ))->send('+8801712345678', 'Campaign message');

        $this->assertTrue($result->success);
        $this->assertSame('ALARIS_123', $result->messageId);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://sms.alaris.test:8002/api?command=submit'
                && $request['ani'] === 'WISPER'
                && $request['dnis'] === '8801712345678'
                && $request['message'] === 'Campaign message'
                && str_starts_with($request->header('Authorization')[0] ?? '', 'Basic ');
        });
    }

    public function test_alaris_driver_queries_and_maps_delivery_status(): void
    {
        Http::fake([
            'https://sms.alaris.test:8002/api?command=query*' => Http::response([
                'status' => 'DELIVRD',
            ], 200),
        ]);

        $status = (new AlarisSmsDriver(
            'https://sms.alaris.test:8002/api',
            'client-user',
            'client-password',
            'WISPER',
        ))->status('ALARIS_123');

        $this->assertSame('delivered', $status->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'command=query') && str_contains($request->url(), 'messageId=ALARIS_123'));
    }
}
