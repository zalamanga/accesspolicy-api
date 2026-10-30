<?php
namespace App\Services;

class MutationCheckpoint
{
    // Tests replace this service to fail between revocation and commit. No HTTP or environment switch exists.
    public function afterTokenRevocation(): void {}
}
