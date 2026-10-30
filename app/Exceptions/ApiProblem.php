<?php
namespace App\Exceptions;

final class ApiProblem extends \RuntimeException
{
    public function __construct(public int $status, public string $problemCode, string $message, public ?array $details = null)
    {
        parent::__construct($message);
    }
}
