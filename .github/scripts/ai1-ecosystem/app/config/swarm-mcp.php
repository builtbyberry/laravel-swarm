<?php

return array_replace_recursive(require base_path('vendor/builtbyberry/laravel-swarm-mcp/config/swarm-mcp.php'), [
    'transports' => ['http' => ['enabled' => true, 'path' => 'swarm-mcp']],
    'authentication' => ['middleware' => ['auth:c5']],
]);
