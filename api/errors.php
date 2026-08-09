<?php
/** Convert PHP notices/warnings/errors into JSON error responses. */

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    error_log("[botroster] php error: $message in $file:$line");
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['error' => 'php_error', 'message' => $message]);
        exit;
    }
    return true;
});

set_exception_handler(function ($e) {
    error_log('[botroster] uncaught: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
    }
});
