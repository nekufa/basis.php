<?php

namespace Basis\Job\Module;

use Basis\Job;
use Swoole\Coroutine;
use Swoole\Error;

class Sleep extends Job
{
    public float $seconds = 1;

    public function run()
    {
        if (class_exists(Coroutine::class)) {
            try {
                return Coroutine::sleep($this->seconds);
            } catch (Error $error) {
                // API must be called in the coroutine
            }
        }

        return sleep($this->seconds);
    }
}
