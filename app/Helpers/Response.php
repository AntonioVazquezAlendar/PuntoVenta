<?php

class Response
{
    public static function json($success, $message, $data = array(), $code = 200)
    {
        http_response_code($code);

        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(array(
            'success' => $success,
            'message' => $message,
            'total' => is_array($data) ? count($data) : 0,
            'data' => $data
        ), JSON_UNESCAPED_UNICODE);

        exit;
    }
}
