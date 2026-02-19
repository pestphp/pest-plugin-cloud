<?php

declare(strict_types=1);

namespace Pest\PestCloud;

use Pest\Contracts\Plugins\HandlesArguments;
use Pest\Plugins\Concerns\HandleArguments;
use Pest\Support\Container;

/**
 * @internal
 */
final class Plugin implements HandlesArguments
{
    use HandleArguments;

    public function handleArguments(array $arguments): array
    {
        if (! $this->hasArgument('--cloud', $arguments)) {
            return $arguments;
        }

        $arguments = $this->popArgument('--cloud', $arguments);

        /** @var CloudRun $cloudRun */
        $cloudRun = Container::getInstance()->get(CloudRun::class);

        $result = $cloudRun->handle($arguments);

        exit($result);
    }
}
