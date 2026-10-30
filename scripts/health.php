<?php
$context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
$body = @file_get_contents('http://127.0.0.1:8000/health', false, $context);
exit($body !== false && (json_decode($body, true)['status'] ?? null) === 'ok' ? 0 : 1);
