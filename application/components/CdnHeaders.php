<?php

namespace app\components;

use yii\web\Response;

/**
 * Cloudflare cache-header helper.
 *
 * Single source of truth for which status codes are CDN-cacheable and what
 * headers we emit. 301s are included because they're permanent and stable;
 * 302s and error codes are not.
 */
class CdnHeaders
{
    public static function attach(Response $response, int $duration, string $cacheTag): void
    {
        $response->on(Response::EVENT_BEFORE_SEND, function ($event) use ($duration, $cacheTag) {
            /** @var Response $r */
            $r = $event->sender;
            if ($r->statusCode !== 200 && $r->statusCode !== 301) {
                return;
            }
            $r->headers->set('Cache-Control', 'no-cache');
            $r->headers->set('Cloudflare-CDN-Cache-Control', 'public, max-age=' . $duration);
            $r->headers->set('Cache-Tag', $cacheTag);
        });
    }
}
