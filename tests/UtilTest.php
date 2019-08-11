<?php

use PHPUnit\Framework\TestCase;
use Islambey\RSMQ\Util;

class UtilTest extends TestCase
{
    /**
     * @var Util
     */
    private $util;

    public function setUp():void
    {
        $this->util = new Util();
    }

    public function testMakeID()
    {
        $size = 20;
        $this->assertSame($size, strlen($this->util->makeID($size)));
    }

    /**
     * @param string $expected
     * @param int $num
     * @param int $count
     * @dataProvider providerFormatZeroPad
     */
    public function testFormatZeroPad($expected, $num, $count)
    {
        $this->assertSame($expected, $this->util->formatZeroPad($num, $count));
    }

    public function providerFormatZeroPad()
    {
        return [
            ['01', 1, 2],
            ['001', 1, 3],
            ['0001', 1, 4],
            ['00001', 1, 5],
            ['000001', 1, 6],
            ['000451', 451, 6],
            ['123456', 123456, 6],
            ['0000123456', 123456, 10],
        ];
    }
}