<?php

namespace App\Traffic;

/** The archive or the quarantine could not be written: a disk problem, not the request log's fault. */
class ArchiveException extends \RuntimeException
{
}
