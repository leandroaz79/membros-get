<?php

namespace Plugins\AutoloadTest;

class HelloService
{
    public static function greet(): string
    {
        return 'hello-plugin';
    }
}
