<?php

class PsLandingPageLogger
{
    const LOG_SEVERITY_INFO = 1;
    const LOG_SEVERITY_WARNING = 2;
    const LOG_SEVERITY_ERROR = 3;

    public static function info($message, array $context = [])
    {
        self::write(self::LOG_SEVERITY_INFO, $message, $context);
    }

    public static function warning($message, array $context = [])
    {
        self::write(self::LOG_SEVERITY_WARNING, $message, $context);
    }

    public static function error($message, array $context = [])
    {
        self::write(self::LOG_SEVERITY_ERROR, $message, $context);
    }

    private static function write($severity, $message, array $context)
    {
        $contextJson = '';
        if (!empty($context)) {
            $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($encoded)) {
                $contextJson = ' | ' . $encoded;
            }
        }

        \PrestaShopLogger::addLog(
            '[pslandingpage] ' . (string) $message . $contextJson,
            (int) $severity,
            null,
            'Module',
            null,
            true
        );
    }
}
