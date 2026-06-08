<?php

namespace App\Upsun;

use Upsun\UpsunClient;

/**
 * Extends the SDK's UpsunClient to declare TasksContainerTask
 * as a typed public property (required in PHP 8.2+).
 */
final class AppUpsunClient extends UpsunClient
{
    public TasksContainerTask $tasksContainer;
}
