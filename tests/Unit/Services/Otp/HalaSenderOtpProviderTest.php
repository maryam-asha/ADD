<?php

namespace Tests\Unit\Services\Otp;

use App\Services\Otp\Drivers\HalaSenderOtpProvider;
use App\Services\Otp\OtpProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HalaSenderOtpProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.otp.driver' => 'hala_sender',
            'services.hala_sender.base_url' => 'https://clientarea.hala-sender.com',
            'services.hala_sender.api_key' => 'test-key',
            'services.hala_sender.device_id' => 42,
        ]);
    }

    public function test_the_hala_sender_driver_is_bound_when_configured(): void
    {
        $this->assertInstanceOf(HalaSenderOtpProvider::class, app(OtpProvider::class));
    }

    public function test_it_posts_the_code_to_the_whatsapp_endpoint_in_international_format(): void
    {
        Http::fake(['*' => Http::response(['statusCode' => 200, 'message' => 'Success', 'data' => null])]);

        $this->assertTrue((new HalaSenderOtpProvider)->send('0912345678', '482913', 'whatsapp'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://clientarea.hala-sender.com/whatsapp/messages/send'
            && $request->hasHeader('X-ApiKey', 'test-key')
            && $request['receivers'] === ['963912345678']
            && str_contains($request['message'], '482913')
            && $request['whatsAppDeviceId'] === 42);
    }

    public function test_an_already_international_number_is_left_as_is(): void
    {
        Http::fake(['*' => Http::response(['statusCode' => 200])]);

        (new HalaSenderOtpProvider)->send('+963912345678', '111111', 'whatsapp');

        Http::assertSent(fn (Request $request) => $request['receivers'] === ['963912345678']);
    }

    public function test_a_rejected_send_returns_false(): void
    {
        Http::fake(['*' => Http::response(['statusCode' => 404], 404)]);

        $this->assertFalse((new HalaSenderOtpProvider)->send('0912345678', '111111', 'whatsapp'));
    }
}
