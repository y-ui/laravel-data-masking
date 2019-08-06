<?php

namespace Yui\DataMasking\Exceptions;

class PrimaryKeyNotFoundException extends \Exception
{

    public function __construct($table, $message = "Primary Key Not Found.", $code = 0, \Throwable $previous = null) {

    }

}