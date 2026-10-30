<?php
return ['default' => 'stderr', 'channels' => ['stderr' => [
    'driver' => 'monolog', 'handler' => Monolog\Handler\StreamHandler::class,
    'with' => ['stream' => 'php://stderr'], 'level' => 'error',
    'processors' => [Monolog\Processor\PsrLogMessageProcessor::class],
]]];
