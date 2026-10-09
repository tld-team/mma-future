<?php

declare(strict_types=1);

namespace MMAFVendor\JsonMachine\JsonDecoder;

class PassThruDecoder implements ItemDecoder
{
    public function decode($jsonValue)
    {
        return new ValidResult($jsonValue);
    }
}
