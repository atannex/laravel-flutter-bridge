<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Unit;

use Atannex\LaravelFlutterBridge\Services\VerificationCodeBroker;
use Atannex\LaravelFlutterBridge\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class VerificationCodeBrokerTest extends TestCase
{
    public function test_codes_are_numeric_hashed_and_single_use(): void
    {
        config(['flutter-bridge.fortify.codes.length' => 8, 'flutter-bridge.fortify.codes.ttl' => 120]);
        $broker = app(VerificationCodeBroker::class);

        $issued = $broker->issue('purpose', 'subject');

        $this->assertMatchesRegularExpression('/^\d{8}$/', $issued['code']);
        $this->assertSame(120, $issued['expires_in']);

        // Only an HMAC is stored.
        $stored = Cache::get('flutter-bridge:code:purpose:'.hash('sha256', 'subject'));
        $this->assertIsArray($stored);
        $this->assertStringNotContainsString($issued['code'], (string) json_encode($stored));

        $this->assertSame(VerificationCodeBroker::INVALID, $broker->verify('other-purpose', 'subject', $issued['code']));
        $this->assertSame(VerificationCodeBroker::INVALID, $broker->verify('purpose', 'other-subject', $issued['code']));
        $this->assertSame(VerificationCodeBroker::VALID, $broker->verify('purpose', 'subject', substr($issued['code'], 0, 4).' '.substr($issued['code'], 4)));
        $this->assertSame(VerificationCodeBroker::INVALID, $broker->verify('purpose', 'subject', $issued['code']));
    }

    public function test_expiry_lockout_and_forget(): void
    {
        config(['flutter-bridge.fortify.codes.max_attempts' => 2]);
        $broker = app(VerificationCodeBroker::class);

        $code = $broker->issue('p', 's')['code'];
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->assertSame(VerificationCodeBroker::INVALID, $broker->verify('p', 's', $wrong));
        $this->assertSame(VerificationCodeBroker::LOCKED, $broker->verify('p', 's', $wrong));
        $this->assertSame(VerificationCodeBroker::LOCKED, $broker->verify('p', 's', $code));

        $code = $broker->issue('p', 's')['code'];
        $this->travel(601)->seconds();
        $this->assertSame(VerificationCodeBroker::EXPIRED, $broker->verify('p', 's', $code));

        $code = $broker->issue('p', 's')['code'];
        $broker->forget('p', 's');
        $this->assertSame(VerificationCodeBroker::INVALID, $broker->verify('p', 's', $code));
    }
}
