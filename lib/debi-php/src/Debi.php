<?php

declare(strict_types=1);

namespace Debi;

/**
 * Static configuration and version metadata for the SDK.
 *
 * Intentionally tiny. Anything that varies per request belongs on {@see RequestOptions};
 * anything that varies per client instance belongs on {@see DebiClient}.
 */
final class Debi
{
    /**
     * SDK version. Single source of truth: the VERSION file at the package root.
     */
    public const VERSION = '0.1.0';

    /**
     * Default Debi API version pinned by this SDK release. Sent via the `Debi-Version`
     * header so that server-side changes do not silently affect existing integrations.
     */
    public const API_VERSION = '2025-10-02';

    private static ?string $appName = null;
    private static ?string $appVersion = null;
    private static ?string $appUrl = null;

    /**
     * Identify the application built on top of this SDK. Surfaces in the `User-Agent`
     * to help Debi support diagnose issues for plugin / framework integrations.
     */
    public static function setAppInfo(string $name, ?string $version = null, ?string $url = null): void
    {
        self::$appName = $name;
        self::$appVersion = $version;
        self::$appUrl = $url;
    }

    /**
     * @return array{name: ?string, version: ?string, url: ?string}
     */
    public static function getAppInfo(): array
    {
        return [
            'name' => self::$appName,
            'version' => self::$appVersion,
            'url' => self::$appUrl,
        ];
    }

    public static function userAgent(): string
    {
        $ua = sprintf('Debi/%s PHP/%s', self::VERSION, PHP_VERSION);

        if (self::$appName !== null) {
            $ua .= ' ' . self::$appName;
            if (self::$appVersion !== null) {
                $ua .= '/' . self::$appVersion;
            }
            if (self::$appUrl !== null) {
                $ua .= ' (' . self::$appUrl . ')';
            }
        }

        return $ua;
    }
}
