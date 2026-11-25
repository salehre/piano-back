<?php

namespace Tests\Unit;

use App\Support\ClubLinks;
use PHPUnit\Framework\TestCase;

class ClubLinksTest extends TestCase
{
    public function test_signature_is_stable_and_bound_to_everything(): void
    {
        $sig = ClubLinks::signature('confirm', 7, 'a@b.co', 'secret');

        $this->assertSame($sig, ClubLinks::signature('confirm', 7, 'A@B.co', 'secret'));
        $this->assertTrue(ClubLinks::isValid('confirm', 7, 'a@b.co', 'secret', $sig));
        $this->assertFalse(ClubLinks::isValid('unsubscribe', 7, 'a@b.co', 'secret', $sig));
        $this->assertFalse(ClubLinks::isValid('confirm', 8, 'a@b.co', 'secret', $sig));
        $this->assertFalse(ClubLinks::isValid('confirm', 7, 'x@b.co', 'secret', $sig));
        $this->assertFalse(ClubLinks::isValid('confirm', 7, 'a@b.co', 'other', $sig));
        $this->assertFalse(ClubLinks::isValid('confirm', 7, 'a@b.co', 'secret', ''));
    }

    public function test_url_shape(): void
    {
        $sig = ClubLinks::signature('unsubscribe', 3, 'a@b.co', 'k');

        $this->assertSame(
            'https://x.test/piano/Api/api/club/unsubscribe/3/'.$sig,
            ClubLinks::url('https://x.test/piano/Api/', 'unsubscribe', 3, 'a@b.co', 'k'),
        );
        $this->assertMatchesRegularExpression('#^/api/club/confirm/3/[a-f0-9]{64}$#', ClubLinks::url('', 'confirm', 3, 'a@b.co', 'k'));
    }
}