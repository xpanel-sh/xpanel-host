<?php

namespace Tests\Unit;

use App\Services\SystemDnsResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SystemDnsResolverTest extends TestCase
{
    public function test_mx_verification_uses_a_fresh_public_dns_answer(): void
    {
        Http::fake([
            'cloudflare-dns.com/*' => Http::response([
                'Status' => 0,
                'Answer' => [
                    ['name' => 'ronaldbit.com.', 'type' => 15, 'data' => '10 mail.cloud.doolpool.com.'],
                ],
            ]),
        ]);

        $records = app(SystemDnsResolver::class)->records('ronaldbit.com', DNS_MX);

        $this->assertSame([[
            'target' => 'mail.cloud.doolpool.com',
            'pri' => 10,
        ]], $records);
    }
}
