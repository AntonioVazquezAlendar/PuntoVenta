<?php

class HttpError extends RuntimeException
{
    public function __construct($message, $status = 422)
    {
        parent::__construct($message, $status);
    }
}
