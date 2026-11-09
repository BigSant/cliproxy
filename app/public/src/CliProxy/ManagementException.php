<?php

namespace App\CliProxy;

/** CLIProxyAPI refused a management call or did not answer; the message is safe to show (it never holds a key). */
class ManagementException extends \RuntimeException
{
}
