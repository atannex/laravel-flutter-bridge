<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Unit;

use Atannex\LaravelFlutterBridge\Services\FlutterResponse;
use Atannex\LaravelFlutterBridge\Tests\TestCase;

class FlutterResponseTest extends TestCase
{
    public function test_success_response_structure(): void
    {
        $response = FlutterResponse::success(['id' => 1, 'name' => 'John'], 'Profile fetched');
        $data = $response->getData(true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($data['success']);
        $this->assertEquals('Profile fetched', $data['message']);
        $this->assertEquals(['id' => 1, 'name' => 'John'], $data['data']);
        $this->assertNull($data['errors']);
        $this->assertArrayHasKey('meta', $data);
        $this->assertArrayHasKey('timestamp', $data['meta']);
    }

    public function test_error_response_structure(): void
    {
        $response = FlutterResponse::error('Item not found', 404, ['item' => ['Item 999 does not exist']]);
        $data = $response->getData(true);

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertFalse($data['success']);
        $this->assertEquals('Item not found', $data['message']);
        $this->assertNull($data['data']);
        $this->assertEquals(['item' => ['Item 999 does not exist']], $data['errors']);
    }
}
