<?php

declare(strict_types=1);

namespace Pest\PestCloud;

/**
 * @internal
 */
class CloudRun
{
    /**
     * @param  array<int, string>  $arguments
     */
    public function handle(array $arguments): int
    {
        dd($arguments);

        return 0;
    }
}
