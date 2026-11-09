<?php

namespace App\CliProxy;

/** config.yaml changed between reading it and saving it (CLIProxyAPI's 412 on the ETag): nothing was saved. */
class ConfigConflictException extends ManagementException
{
}
