<?php

return array_replace_recursive(require base_path('vendor/builtbyberry/laravel-swarm/config/swarm.php'), [
    'persistence' => ['driver' => 'database', 'encrypt_at_rest' => true],
    'capture' => ['inputs' => true, 'outputs' => true, 'artifacts' => true, 'active_context' => true],
]);
