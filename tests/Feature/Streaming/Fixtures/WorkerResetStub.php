<?php

declare(strict_types=1);

// Match the optional Octane reset contract for tests without installing Octane.

namespace Laravel\Octane\Contracts {
    if (! interface_exists(OperationTerminated::class)) {
        interface OperationTerminated {}
    }
}

namespace Laravel\Octane\Events {
    use Laravel\Octane\Contracts\OperationTerminated;

    if (! class_exists(RequestTerminated::class)) {
        class RequestTerminated implements OperationTerminated {}
    }
}
